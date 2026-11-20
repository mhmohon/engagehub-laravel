<?php

namespace EngageHub\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use EngageHub\Exceptions\ValidationException;
use EngageHub\EngageHubClient;

/**
 * Carries an array snapshot (never the model) so the sync reflects the
 * state at dispatch time and survives model deletion. Upserts are
 * idempotent, so queue retries are safe.
 */
class SyncContactToEngageHub implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $contact
     */
    public function __construct(public array $contact, public bool $backfill = false) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(EngageHubClient $client): void
    {
        try {
            $result = $this->backfill
                ? ($client->contacts()->upsertBatch([$this->contact], true)->results[0] ?? new \EngageHub\Dto\ItemResult(0, 'rejected'))
                : $client->contacts()->upsert($this->contact);
        } catch (ValidationException $e) {
            // Malformed payloads never heal — fail without retrying.
            Log::warning('EngageHub contact sync payload invalid', [
                'errors' => $e->errors(),
                'contact' => $this->contact,
            ]);
            $this->fail($e);

            return;
        }

        if ($result->rejected()) {
            Log::warning('EngageHub rejected contact sync', [
                'identity' => $result->identity,
                'errors' => $result->errors,
            ]);
        }
    }
}
