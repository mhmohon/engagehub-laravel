<?php

namespace EngageHub\Laravel\Jobs;

use EngageHub\Exceptions\ValidationException;
use EngageHub\Laravel\EngageHubManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Upsert a plan, subscription or object (an array snapshot, never the model).
 * Upserts are idempotent, so retries are safe.
 */
class SyncRecordToEngageHub implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $record
     */
    public function __construct(public string $kind, public array $record, public bool $backfill = false) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(EngageHubManager $engagehub): void
    {
        try {
            $result = $engagehub->resource($this->kind)->upsertBatch([$this->record], $this->backfill);
        } catch (ValidationException $e) {
            Log::warning("EngageHub {$this->kind} payload invalid", ['errors' => $e->errors(), 'record' => $this->record]);
            $this->fail($e);

            return;
        }

        foreach ($result->rejectedItems() as $item) {
            Log::warning("EngageHub rejected {$this->kind} sync", ['identity' => $item->identity, 'errors' => $item->errors]);
        }
    }
}
