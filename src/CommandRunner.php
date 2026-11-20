<?php

namespace EngageHub\Laravel;

use EngageHub\EngageHubClient;
use EngageHub\Laravel\Console\SyncCommand;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs a command EngageHub left for this app, whichever way it arrived: pushed
 * to POST /engagehub/commands (RunEngageHubCommand job) or picked up by
 * `engagehub:poll`. A command runs once: a cache lock on its id keeps the
 * pushed run and a poll from both starting it.
 */
class CommandRunner
{
    public const LOCK_SECONDS = 86400;

    public function __construct(private Application $app, private EngageHubClient $client) {}

    /**
     * @param  array{id: string, type: string}  $command
     * @return 'done'|'failed'|'skipped'|'unknown'
     */
    public function run(array $command, ?OutputInterface $output = null): string
    {
        if ($command['type'] !== 'backfill') {
            return 'unknown';
        }

        return $this->backfill($command['id'], $output ?? new NullOutput);
    }

    /** @return 'done'|'failed'|'skipped' */
    private function backfill(string $commandId, OutputInterface $output): string
    {
        if (! Cache::add('engagehub:command:'.$commandId, true, self::LOCK_SECONDS)) {
            $output->writeln('This sync is already running or done here.', OutputInterface::VERBOSITY_VERBOSE);

            return 'skipped';
        }

        // Claim it in EngageHub too: the pending command is cleared, so other
        // servers of this app polling it later do not run it again.
        try {
            $this->client->reportSync(['command_id' => $commandId, 'status' => 'running']);
        } catch (Throwable $e) {
            // Not ours to run (superseded) or EngageHub unreachable: let a later poll try again.
            Cache::forget('engagehub:command:'.$commandId);
            $output->writeln('<comment>Could not claim the sync (it may be superseded): '.$e->getMessage().'</comment>');

            return 'skipped';
        }

        $output->writeln('<info>EngageHub requested a sync of existing data. Starting.</info>');

        $sync = $this->app->make(SyncCommand::class);
        $sync->setLaravel($this->app);

        try {
            $exit = $sync->run(new ArrayInput([]), $output);

            $this->client->reportSync(['command_id' => $commandId, 'status' => 'done'] + $sync->totals() + [
                'message' => $exit === 0 ? null : 'Some records were rejected. See the request log.',
            ]);

            return 'done';
        } catch (Throwable $e) {
            report($e);

            try {
                $this->client->reportSync(['command_id' => $commandId, 'status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 1000)] + $sync->totals());
            } catch (Throwable) {
                // EngageHub is unreachable too; the error is already reported locally.
            }

            $output->writeln('<error>Sync failed: '.$e->getMessage().'</error>');

            return 'failed';
        }
    }
}
