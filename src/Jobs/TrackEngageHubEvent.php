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
 * The event array already carries its event_id (stamped at dispatch), so
 * queue retries dedupe server-side instead of double-counting.
 */
class TrackEngageHubEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(public array $event) {}

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
            $result = $client->events()->trackBatch([$this->event]);
        } catch (ValidationException $e) {
            Log::warning('EngageHub event payload invalid', [
                'errors' => $e->errors(),
                'event' => $this->event,
            ]);
            $this->fail($e);

            return;
        }

        foreach ($result->rejectedItems() as $item) {
            Log::warning('EngageHub rejected event', [
                'name' => $item->name,
                'identity' => $item->identity,
                'errors' => $item->errors,
            ]);
        }
    }
}
