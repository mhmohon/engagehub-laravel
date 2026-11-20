<?php

namespace EngageHub\Laravel\Http;

use EngageHub\Laravel\Jobs\RunEngageHubCommand;
use EngageHub\Webhooks\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /engagehub/commands: EngageHub asks this app to run a command now
 * ("Sync existing data"), instead of waiting for the next `engagehub:poll`.
 *
 * Signed like EngageHub's webhooks (EngageHub-Signature: t=…,v1=…). The secret
 * is the SHA-256 of this app's API key: EngageHub keeps only that hash, and the
 * app can compute it, so there is nothing extra to configure.
 *
 * The work goes on the queue; the response only says it was accepted.
 */
class CommandController
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) config('engagehub.api_key');

        if (! config('engagehub.enabled', true) || ! config('engagehub.commands.enabled', true) || $key === '') {
            return response()->json(['message' => 'Commands are disabled.'], 404);
        }

        $body = $request->getContent();

        if (! WebhookSignature::isValid($body, (string) $request->header('EngageHub-Signature', ''), hash('sha256', $key))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $command = json_decode($body, true);

        if (! is_array($command) || ! is_string($command['id'] ?? null) || ! is_string($command['type'] ?? null)) {
            return response()->json(['message' => 'Malformed command.'], 422);
        }

        if ($command['type'] !== 'backfill') {
            return response()->json(['message' => "Unknown command type \"{$command['type']}\"; update engagehub/laravel."], 422);
        }

        RunEngageHubCommand::dispatch(['id' => $command['id'], 'type' => $command['type']])
            ->onConnection(config('engagehub.queue.connection'))
            ->onQueue(config('engagehub.queue.name'));

        return response()->json(['accepted' => true], 202);
    }
}
