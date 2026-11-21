<?php

namespace EngageHub\Laravel\Console;

use Illuminate\Console\Command;
use Throwable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use EngageHub\Dto\SyncResult;
use EngageHub\Exceptions\RateLimitException;
use EngageHub\Laravel\Jobs\SyncContactToEngageHub;
use EngageHub\Laravel\Jobs\SyncRecordToEngageHub;
use EngageHub\Laravel\EngageHubManager;

/**
 * One-time (or resumable) backfill: pushes every existing row of the
 * registered models into EngageHub (contacts, plans, subscriptions, objects),
 * in rate-limit-aware batches. Plans and contacts go first, so subscriptions
 * and objects find them. EngageHub records no change events for a backfill
 * unless --with-events is given.
 */
class SyncCommand extends Command
{
    protected $signature = 'engagehub:sync
        {--model=* : Model class(es) to backfill (default: config engagehub.sync.models)}
        {--after-id=0 : Resume after this primary key value}
        {--chunk= : Rows per request (default config engagehub.sync.chunk, max 500)}
        {--rpm=300 : Max requests per minute (0 = no pacing)}
        {--queue : Dispatch queued jobs instead of syncing inline}
        {--dry-run : Report what would be synced without sending anything}
        {--with-events : Let EngageHub record change events (contact_created, trial_started...) for these rows}';

    protected $description = 'Backfill existing rows of the registered models into EngageHub';

    private int $created = 0;

    private int $updated = 0;

    private int $rejected = 0;

    /** The row being turned into a payload, so a failure can say where it happened. */
    private ?string $at = null;

    public function handle(EngageHubManager $engagehub): int
    {
        if (! $engagehub->enabled() && ! $this->option('dry-run')) {
            $this->error('EngageHub is disabled or ENGAGEHUB_API_KEY is missing.');

            return self::FAILURE;
        }

        /** @var list<class-string<Model>> $models */
        $models = $this->option('model') ?: $engagehub->configuredModels();

        if ($models === []) {
            $this->error('Nothing to sync: pass --model="App\\Models\\User" or register models in config/engagehub.php (engagehub.sync.models).');

            return self::FAILURE;
        }

        $chunk = min((int) ($this->option('chunk') ?: config('engagehub.sync.chunk', 500)), 500);

        foreach ($models as $class) {
            if (! class_exists($class)) {
                $this->error("Model {$class} does not exist.");

                return self::FAILURE;
            }
        }

        if (! $this->option('dry-run')) {
            try {
                if ($engagehub->describe(force: true)) {
                    $this->line('Sent '.count($engagehub->meters()).' meter(s) to EngageHub.');
                }
            } catch (Throwable $e) {
                $this->warn('Could not send meters to EngageHub: '.$e->getMessage());
            }
        }

        // Plans and people before what refers to them.
        usort($models, fn (string $a, string $b) => $this->rank($engagehub, $a) <=> $this->rank($engagehub, $b));

        foreach ($models as $class) {
            $this->syncModel($engagehub, $class, $chunk);
        }

        $this->newLine();
        $this->info("Done: {$this->created} created, {$this->updated} updated, {$this->rejected} rejected.");

        if ($this->rejected > 0) {
            $this->warn('Some items were rejected — see the errors above (and EngageHub\'s ingest log).');
        }

        return $this->rejected > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Counts from the last run, for callers such as engagehub:poll.
     *
     * @return array{created: int, updated: int, rejected: int}
     */
    public function totals(): array
    {
        return ['created' => $this->created, 'updated' => $this->updated, 'rejected' => $this->rejected];
    }

    /** Where the last run was when it stopped: "App\Models\Order, id 42", or null. */
    public function at(): ?string
    {
        return $this->at;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function syncModel(EngageHubManager $engagehub, string $class, int $chunk): void
    {
        $model = new $class;
        $keyName = $model->getKeyName();
        $afterId = (int) $this->option('after-id');

        $query = $class::query();

        if ($afterId > 0) {
            $query->where($keyName, '>', $afterId);
        }

        $kind = $engagehub->kindOf($class);
        $total = (clone $query)->count();
        $this->components->info("Syncing {$class} as {$kind}: {$total} rows".($afterId > 0 ? " (after {$keyName} {$afterId})" : ''));

        if ($total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);

        $this->at = $class;

        $query->orderBy($keyName)->chunkById($chunk, function (Collection $rows) use ($engagehub, $keyName, $bar, $kind, $class): void {
            $payloads = $rows
                ->filter(fn (Model $row): bool => $engagehub->shouldSync($row))
                ->map(function (Model $row) use ($engagehub, $keyName, $class): array {
                    $this->at = "{$class}, {$keyName} ".$row->getKey();

                    return $engagehub->recordPayload($row);
                })
                // Not a record: a logged webhook that moved no money, a payment with no amount.
                ->filter(fn (array $payload): bool => ($payload['external_id'] ?? '') !== '')
                ->values()
                ->all();

            if ($payloads !== [] && ! $this->option('dry-run')) {
                $this->option('queue')
                    ? $this->dispatchJobs($kind, $payloads)
                    : $this->report($this->sendChunk($engagehub, $kind, $payloads));
            }

            $bar->advance(count($rows));
            $lastId = $rows->last()?->getAttribute($keyName);
            $bar->setMessage("last {$keyName}: {$lastId}");

            $this->pace();
        }, $keyName);

        $bar->finish();
        $this->newLine();
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function sendChunk(EngageHubManager $engagehub, string $kind, array $payloads): SyncResult
    {
        $send = fn (): SyncResult => $kind === 'contact'
            ? $engagehub->client()->contacts()->upsertBatch($payloads, $this->backfill())
            : $engagehub->resource($kind)->upsertBatch($payloads, $this->backfill());

        try {
            return $send();
        } catch (RateLimitException $e) {
            // The client already retried; wait the server's word and give
            // the chunk one more chance before bailing out loudly.
            $wait = $e->retryAfter() ?? 60;
            $this->warn("Rate limited — waiting {$wait}s before retrying this chunk.");
            sleep($wait);

            return $send();
        }
    }

    private function backfill(): bool
    {
        return ! $this->option('with-events');
    }

    private function rank(EngageHubManager $engagehub, string $class): int
    {
        $kind = $engagehub->kindOf($class);

        return EngageHubManager::KIND_ORDER[str_starts_with($kind, 'object:') ? 'object' : $kind] ?? 9;
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function dispatchJobs(string $kind, array $payloads): void
    {
        foreach ($payloads as $payload) {
            ($kind === 'contact'
                ? SyncContactToEngageHub::dispatch($payload, $this->backfill())
                : SyncRecordToEngageHub::dispatch($kind, $payload, $this->backfill()))
                ->onConnection(config('engagehub.queue.connection'))
                ->onQueue(config('engagehub.queue.name'));
        }
    }

    private function report(SyncResult $result): void
    {
        $this->created += $result->created;
        $this->updated += $result->updated;
        $this->rejected += $result->rejected;

        foreach ($result->rejectedItems() as $item) {
            $this->newLine();
            $this->warn("  rejected [{$item->identity}]: ".json_encode($item->errors));
        }
    }

    private function pace(): void
    {
        $rpm = (int) $this->option('rpm');

        if ($rpm > 0 && ! $this->option('dry-run') && ! $this->option('queue')) {
            usleep((int) (60_000_000 / $rpm));
        }
    }
}
