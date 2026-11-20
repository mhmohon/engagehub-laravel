<?php

namespace EngageHub\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use EngageHub\Config;
use EngageHub\Laravel\Console\PollCommand;
use EngageHub\Laravel\Console\StatusCommand;
use EngageHub\Laravel\Console\SyncCommand;
use EngageHub\Laravel\Http\CommandController;
use EngageHub\EngageHubClient;

class EngageHubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/engagehub.php', 'engagehub');

        $this->app->singleton(EngageHubClient::class, function (Application $app): EngageHubClient {
            $config = $app['config']['engagehub'];

            return new EngageHubClient(new Config(
                apiKey: (string) ($config['api_key'] ?? ''),
                baseUrl: (string) ($config['base_url'] ?? ''),
                timeout: (int) ($config['timeout'] ?? 10),
                maxRetries: (int) ($config['max_retries'] ?? 3),
                group: ($config['group'] ?? null) ?: null,
                userAgent: 'engagehub-laravel/'.EngageHubClient::VERSION,
                commandUrl: self::commandUrl($config),
            ));
        });

        $this->app->singleton(EngageHubManager::class);
    }

    /**
     * Where EngageHub can push commands to this app, or null when they are off.
     *
     * @param  array<string, mixed>  $config
     */
    public static function commandUrl(array $config): ?string
    {
        if (! ($config['commands']['enabled'] ?? true)) {
            return null;
        }

        $url = $config['commands']['url'] ?? null;

        if (is_string($url) && $url !== '') {
            return $url;
        }

        $app = rtrim((string) config('app.url'), '/');

        return $app === '' ? null : $app.'/'.ltrim((string) ($config['commands']['path'] ?? 'engagehub/commands'), '/');
    }

    public function boot(): void
    {
        // No web middleware: the request is signed, and there is no session or CSRF token.
        if (config('engagehub.commands.enabled', true)) {
            $this->app['router']->post(
                ltrim((string) config('engagehub.commands.path', 'engagehub/commands'), '/'),
                CommandController::class,
            )->name('engagehub.commands');
        }

        $this->publishes([
            __DIR__.'/../config/engagehub.php' => config_path('engagehub.php'),
        ], 'engagehub-config');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCommand::class, StatusCommand::class, PollCommand::class]);

            // "Sync existing data" in EngageHub waits for this check-in.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                if (config('engagehub.poll.enabled', true) && config('engagehub.enabled', true)) {
                    $schedule->command('engagehub:poll')
                        ->everyMinute()
                        ->withoutOverlapping(120)
                        ->runInBackground();
                }
            });
        }

        // Config-registered models get the observer without needing the
        // trait (trait models register themselves in bootSyncsToEngageHub).
        foreach ($this->app->make(EngageHubManager::class)->configuredModels() as $class) {
            if (class_exists($class) && ! in_array(
                Concerns\SyncsToEngageHub::class,
                class_uses_recursive($class),
                true,
            )) {
                $class::observe(EngageHubModelObserver::class);
            }
        }
    }
}
