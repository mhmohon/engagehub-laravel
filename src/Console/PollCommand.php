<?php

namespace EngageHub\Laravel\Console;

use EngageHub\EngageHubClient;
use EngageHub\Laravel\EngageHubManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks in with EngageHub and runs any command left for this app, such as
 * "Sync existing data" from the Integrations page. EngageHub cannot reach into
 * your app, so it waits for this poll.
 *
 * The service provider schedules it every minute (engagehub.poll.enabled), so
 * a running `schedule:run` cron is all an app needs. `--watch` keeps polling in
 * the foreground for local testing without the scheduler.
 */
class PollCommand extends Command
{
    protected $signature = 'engagehub:poll
        {--watch : Keep polling until stopped (local testing without the scheduler)}
        {--interval=30 : Seconds between polls with --watch}';

    protected $description = 'Check in with EngageHub and run any command it left for this app (e.g. "Sync existing data")';

    public function handle(EngageHubManager $engagehub, EngageHubClient $client): int
    {
        if (! $engagehub->enabled()) {
            $this->error('EngageHub is disabled or ENGAGEHUB_API_KEY is missing.');

            return self::FAILURE;
        }

        if (! $this->option('watch')) {
            return $this->pollOnce($client);
        }

        $interval = max(5, (int) $this->option('interval'));
        $this->info("Polling EngageHub every {$interval}s. Ctrl+C to stop.");

        while (true) { // @phpstan-ignore while.alwaysTrue
            $this->pollOnce($client);
            sleep($interval);
        }
    }

    private function pollOnce(EngageHubClient $client): int
    {
        try {
            $command = $client->connection()['pending_command'] ?? null;
        } catch (Throwable $e) {
            $this->error('EngageHub check-in failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($command) || ! isset($command['id'], $command['type'])) {
            $this->line('No pending command.', verbosity: 'v');

            return self::SUCCESS;
        }

        if ($command['type'] !== 'backfill') {
            $this->warn("Unknown command type \"{$command['type']}\"; update engagehub/laravel.");

            return self::SUCCESS;
        }

        return $this->backfill($client, (string) $command['id']);
    }

    private function backfill(EngageHubClient $client, string $commandId): int
    {
        // Claim it first: EngageHub clears the pending command, so an
        // overlapping poll (another server, the next minute) won't run it twice.
        try {
            $client->reportSync(['command_id' => $commandId, 'status' => 'running']);
        } catch (Throwable $e) {
            $this->warn('Could not claim the sync (another poll may have taken it): '.$e->getMessage());

            return self::SUCCESS;
        }

        $this->info('EngageHub requested a sync of existing data. Starting.');

        $sync = $this->laravel->make(SyncCommand::class);
        $sync->setLaravel($this->laravel);

        try {
            $exit = $this->runCommand($sync, [], $this->output);
            $totals = $sync->totals();

            $client->reportSync(['command_id' => $commandId, 'status' => 'done'] + $totals + [
                'message' => $exit === self::SUCCESS ? null : 'Some records were rejected. See the request log.',
            ]);

            return $exit;
        } catch (Throwable $e) {
            report($e);

            try {
                $client->reportSync(['command_id' => $commandId, 'status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 1000)] + $sync->totals());
            } catch (Throwable) {
                // EngageHub is unreachable too; the error is already reported locally.
            }

            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
