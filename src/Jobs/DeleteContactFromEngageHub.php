<?php

namespace EngageHub\Laravel\Jobs;

use EngageHub\EngageHubClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Your app deleted a user: DELETE /contacts/{external_id}. Idempotent, and an
 * id EngageHub never knew is not an error, so retries are safe.
 */
class DeleteContactFromEngageHub implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public string $externalId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(EngageHubClient $client): void
    {
        $client->contacts()->delete($this->externalId);
    }
}
