<?php

namespace EngageHub\Laravel\Jobs;

use EngageHub\Laravel\EngageHubManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A subscription or object was deleted in your app. Idempotent; an id EngageHub
 * never knew is not an error.
 */
class DeleteRecordFromEngageHub implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public string $kind, public string $externalId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(EngageHubManager $engagehub): void
    {
        $engagehub->resource($this->kind)->delete($this->externalId);
    }
}
