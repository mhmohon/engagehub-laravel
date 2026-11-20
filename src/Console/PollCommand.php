<?php

namespace EngageHub\Laravel\Console;

use EngageHub\EngageHubClient;
use EngageHub\Laravel\CommandRunner;
use EngageHub\Laravel\EngageHubManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Checks in with EngageHub and runs any command left for this app, such as
 * "Sync existing data" from the Integrations page. When EngageHub can reach the
 * app it pushes the command to POST /engagehub/commands at once; this poll is
 * the backup for when it cannot (and for apps with commands turned off).
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

        $result = $this->laravel->make(CommandRunner::class)->run(
            ['id' => (string) $command['id'], 'type' => (string) $command['type']],
            $this->output,
        );

        if ($result === 'unknown') {
            $this->warn("Unknown command type \"{$command['type']}\"; update engagehub/laravel.");
        }

        return $result === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
