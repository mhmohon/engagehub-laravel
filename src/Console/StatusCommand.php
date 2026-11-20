<?php

namespace EngageHub\Laravel\Console;

use Illuminate\Console\Command;
use EngageHub\Exceptions\EngageHubException;
use EngageHub\Laravel\EngageHubManager;

/**
 * Connectivity check: verifies the configured key against GET /ping and
 * prints what it is allowed to do.
 */
class StatusCommand extends Command
{
    protected $signature = 'engagehub:status';

    protected $description = 'Verify the EngageHub connection and show the API key\'s group, connection and scopes';

    protected $aliases = ['engagehub:test'];

    public function handle(EngageHubManager $engagehub): int
    {
        if (! $engagehub->enabled()) {
            $this->error('EngageHub is disabled or ENGAGEHUB_API_KEY is missing (check config/engagehub.php and your .env).');

            return self::FAILURE;
        }

        try {
            $info = $engagehub->status();
        } catch (EngageHubException $e) {
            $this->error('EngageHub connection failed: '.$e->getMessage());
            $this->line('base_url: '.config('engagehub.base_url'));

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Group', (string) ($info['group']['name'] ?? '—').' ('.($info['group']['slug'] ?? '—').')');
        $this->components->twoColumnDetail('Connection', (string) ($info['connection']['name'] ?? '—').' · '.($info['connection']['environment'] ?? '—'));
        $this->components->twoColumnDetail('API key', (string) ($info['key']['name'] ?? '—').' ('.($info['key']['prefix'] ?? '—').'…)');
        $this->components->twoColumnDetail('Scopes', implode(', ', (array) ($info['scopes'] ?? [])) ?: '—');
        $this->components->twoColumnDetail('Base URL', (string) config('engagehub.base_url'));
        $this->components->twoColumnDetail('Dispatch', (string) config('engagehub.dispatch'));

        $this->info('Connection OK.');

        return self::SUCCESS;
    }
}
