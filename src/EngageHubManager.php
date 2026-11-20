<?php

namespace EngageHub\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use EngageHub\Dto\ItemResult;
use EngageHub\Laravel\Jobs\DeleteContactFromEngageHub;
use EngageHub\Laravel\Jobs\DeleteRecordFromEngageHub;
use EngageHub\Laravel\Jobs\SyncContactToEngageHub;
use EngageHub\Laravel\Jobs\SyncRecordToEngageHub;
use EngageHub\Laravel\Jobs\TrackEngageHubEvent;
use EngageHub\EngageHubClient;
use EngageHub\Resources\Records;
use EngageHub\Support\Uuid;
use InvalidArgumentException;

/**
 * The bridge brain: turns models into EngageHub payloads (contacts, plans,
 * subscriptions, objects), decides queue-vs-inline dispatch, and is what the
 * EngageHub facade resolves to.
 */
class EngageHubManager
{
    /** Backfill order: plans and people before what refers to them. */
    public const KIND_ORDER = ['plan' => 0, 'contact' => 1, 'subscription' => 2, 'payment' => 3, 'object' => 4];

    public function __construct(private readonly EngageHubClient $client) {}

    public function enabled(): bool
    {
        return (bool) config('engagehub.enabled') && ! empty(config('engagehub.api_key'));
    }

    public function client(): EngageHubClient
    {
        return $this->client;
    }

    /**
     * Track an event. event_id is stamped here — before any queueing — so
     * queue retries stay idempotent.
     *
     * Pass the user model when you have it: the event then carries both the
     * external id and the email, so EngageHub can create the contact even if
     * this job runs before the user's sync job.
     *
     * @param  Model|array<string, mixed>  $identity  a synced model, or external_id and/or email
     * @param  array<string, mixed>  $properties
     */
    public function track(string $name, Model|array $identity = [], array $properties = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($identity instanceof Model) {
            $identity = $this->contactPayload($identity);
        }

        $event = array_intersect_key($identity, ['external_id' => true, 'email' => true, 'occurred_at' => true, 'event_id' => true])
            + ['name' => $name];

        if (isset($event['external_id'])) {
            $event['external_id'] = (string) $event['external_id'];
        }

        if ($properties !== []) {
            $event['properties'] = $properties;
        }

        $event['event_id'] ??= Uuid::v4();

        if ($this->queues()) {
            TrackEngageHubEvent::dispatch($event)
                ->onConnection(config('engagehub.queue.connection'))
                ->onQueue(config('engagehub.queue.name'))
                ->afterCommit();

            return;
        }

        foreach ($this->client->events()->trackBatch([$event])->rejectedItems() as $item) {
            $this->logRejection('event', $item);
        }
    }

    /**
     * Upsert a contact in EngageHub, from a model (mapped automatically) or a
     * wire-format array.
     */
    public function identify(Model|array $contact): void
    {
        if (! $this->enabled()) {
            return;
        }

        $payload = $contact instanceof Model ? $this->contactPayload($contact) : $contact;

        if ($this->queues()) {
            SyncContactToEngageHub::dispatch($payload)
                ->onConnection(config('engagehub.queue.connection'))
                ->onQueue(config('engagehub.queue.name'))
                ->afterCommit();

            return;
        }

        $result = $this->client->contacts()->upsert($payload);

        if ($result->rejected()) {
            $this->logRejection('contact', $result);
        }
    }

    /**
     * Tell EngageHub your app deleted this user (by model or external id):
     * they leave the group there. Unknown ids are ignored.
     */
    public function forget(Model|string $contact): void
    {
        if (! $this->enabled()) {
            return;
        }

        $externalId = $contact instanceof Model
            ? ($this->contactPayload($contact)['external_id'] ?? null)
            : $contact;

        if ($externalId === null || $externalId === '') {
            return;
        }

        if ($this->queues()) {
            DeleteContactFromEngageHub::dispatch((string) $externalId)
                ->onConnection(config('engagehub.queue.connection'))
                ->onQueue(config('engagehub.queue.name'))
                ->afterCommit();

            return;
        }

        $this->client->contacts()->delete((string) $externalId);
    }

    /**
     * Push any registered model by its kind: contacts go through identify(),
     * plans, subscriptions and objects through their own endpoints.
     */
    public function sync(Model $model): void
    {
        if (! $this->enabled()) {
            return;
        }

        $kind = $this->kindOf($model);

        if ($kind === 'contact') {
            $this->identify($model);

            return;
        }

        $this->dispatchRecord($kind, $this->recordPayload($model));
    }

    /**
     * The model was deleted in your app. Contacts and subscriptions are deleted,
     * objects too; a plan cannot be deleted, so it is marked inactive.
     */
    public function remove(Model $model): void
    {
        if (! $this->enabled()) {
            return;
        }

        $kind = $this->kindOf($model);

        if ($kind === 'contact') {
            $this->forget($model);

            return;
        }

        $externalId = (string) ($this->recordPayload($model)['external_id'] ?? '');

        if ($externalId === '') {
            return;
        }

        if ($kind === 'plan') {
            $this->dispatchRecord('plan', ['external_id' => $externalId, 'active' => false]);

            return;
        }

        // Money is never deleted: a payment that went back is sent as refunded.
        if ($kind === 'payment') {
            return;
        }

        if ($this->queues()) {
            DeleteRecordFromEngageHub::dispatch($kind, $externalId)
                ->onConnection(config('engagehub.queue.connection'))
                ->onQueue(config('engagehub.queue.name'))
                ->afterCommit();

            return;
        }

        $this->resource($kind)->delete($externalId);
    }

    /**
     * contact (default), plan, subscription, payment or object:<type>, from the model's
     * config entry (`'as' => ...`) or an engageHubKind() method.
     */
    public function kindOf(Model|string $model): string
    {
        $class = is_string($model) ? $model : $model::class;
        $kind = $this->mappingFor($class)['as'] ?? null;

        if ($kind === null && ! is_string($model) && method_exists($model, 'engageHubKind')) {
            $kind = $model->engageHubKind();
        }

        $kind ??= 'contact';

        if (! in_array($kind, ['contact', 'plan', 'subscription', 'payment'], true) && preg_match('/^object:[a-z][a-z0-9_]{0,47}$/', (string) $kind) !== 1) {
            throw new InvalidArgumentException("EngageHub: {$class} has an unknown kind \"{$kind}\". Use contact, plan, subscription, payment or object:<type>.");
        }

        return $kind;
    }

    /** The API resource for a non-contact kind. */
    public function resource(string $kind): Records
    {
        return match (true) {
            $kind === 'plan' => $this->client->plans(),
            $kind === 'subscription' => $this->client->subscriptions(),
            $kind === 'payment' => $this->client->payments(),
            str_starts_with($kind, 'object:') => $this->client->objects(substr($kind, 7)),
            default => throw new InvalidArgumentException("No record resource for kind \"{$kind}\"."),
        };
    }

    /**
     * Payload for any kind: contacts as before; other models through their own
     * toEngageHubRecord(), else the config mapping.
     *
     * @return array<string, mixed>
     */
    public function recordPayload(Model $model): array
    {
        $kind = $this->kindOf($model);

        if ($kind === 'contact') {
            return $this->contactPayload($model);
        }

        if (method_exists($model, 'toEngageHubRecord')) {
            return $model->toEngageHubRecord();
        }

        return $this->mappedRecord($model, $this->mappingFor($model::class) ?? [], $kind);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchRecord(string $kind, array $payload): void
    {
        if ($this->queues()) {
            SyncRecordToEngageHub::dispatch($kind, $payload)
                ->onConnection(config('engagehub.queue.connection'))
                ->onQueue(config('engagehub.queue.name'))
                ->afterCommit();

            return;
        }

        $result = $this->resource($kind)->upsert($payload);

        if ($result->rejected()) {
            $this->logRejection($kind, $result);
        }
    }

    /**
     * Config mapping for plans, subscriptions and objects:
     *   'external_id' => 'id', 'map' => [engagehub field => attribute],
     *   'values' => [engagehub field => [your value => engagehub value]],
     *   'contact' => attribute holding the user's id, 'plan' => attribute holding the plan's id,
     *   'attributes' => [attribute, ...]
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function mappedRecord(Model $model, array $mapping, string $kind): array
    {
        $payload = ['external_id' => (string) $model->getAttribute($mapping['external_id'] ?? $model->getKeyName())];

        foreach ($mapping['map'] ?? [] as $field => $attribute) {
            $value = $model->getAttribute($attribute);

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::ATOM);
            } elseif ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            if (is_scalar($value) && isset($mapping['values'][$field][(string) $value])) {
                $value = $mapping['values'][$field][(string) $value];
            }

            $payload[$field] = $value;
        }

        if (isset($mapping['contact']) && ($owner = $model->getAttribute($mapping['contact'])) !== null) {
            $payload['contact'] = ['external_id' => (string) $owner];
        }

        if (in_array($kind, ['subscription', 'payment'], true) && isset($mapping['plan']) && ($plan = $model->getAttribute($mapping['plan'])) !== null) {
            $payload['plan'] = ['external_id' => (string) $plan];
        }

        if ($kind === 'payment' && isset($mapping['subscription']) && ($subscription = $model->getAttribute($mapping['subscription'])) !== null) {
            $payload['subscription'] = ['external_id' => (string) $subscription];
        }

        foreach ($mapping['attributes'] ?? [] as $attribute) {
            $payload['attributes'][$attribute] = $model->getAttribute($attribute);
        }

        if ($kind === 'plan') {
            return $payload;
        }

        $timestamps = $this->timestamps($model);

        return $payload + (in_array($kind, ['subscription', 'payment'], true) ? array_intersect_key($timestamps, ['updated_at' => true]) : $timestamps);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->client->ping();
    }

    /**
     * The EngageHub contact payload for a model: its own toEngageHubContact()
     * (trait or hand-rolled), else the config mapping, else convention.
     *
     * @return array<string, mixed>
     */
    public function contactPayload(Model $model): array
    {
        if (method_exists($model, 'toEngageHubContact')) {
            return $model->toEngageHubContact();
        }

        $mapping = $this->mappingFor($model::class);

        return $mapping !== null
            ? $this->mappedPayload($model, $mapping)
            : $this->defaultContactPayload($model);
    }

    /**
     * Convention mapping: primary key → external_id, email → email, and a
     * `name` attribute split into first/last.
     *
     * @return array<string, mixed>
     */
    public function defaultContactPayload(Model $model): array
    {
        $payload = ['external_id' => (string) $model->getKey()];

        $email = $model->getAttribute('email');
        if (is_string($email) && $email !== '') {
            $payload['email'] = $email;
        }

        foreach (['first_name', 'last_name'] as $attribute) {
            $value = $model->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                $payload[$attribute] = $value;
            }
        }

        $name = $model->getAttribute('name');
        if (! isset($payload['first_name']) && is_string($name) && trim($name) !== '') {
            $parts = preg_split('/\s+/', trim($name), 2);
            $payload['first_name'] = $parts[0];
            if (isset($parts[1])) {
                $payload['last_name'] = $parts[1];
            }
        }

        return $payload + $this->timestamps($model);
    }

    public function shouldSync(Model $model): bool
    {
        return method_exists($model, 'engageHubShouldSync') ? $model->engageHubShouldSync() : true;
    }

    /**
     * Model attributes whose change warrants a sync; null = any change.
     *
     * @return list<string>|null
     */
    public function watchedAttributes(Model $model): ?array
    {
        $mapping = $this->mappingFor($model::class);

        if ($mapping !== null) {
            return array_values(array_unique(array_filter([
                $mapping['external_id'] ?? $model->getKeyName(),
                ...array_values($mapping['map'] ?? []),
                ...($mapping['attributes'] ?? []),
                // Columns behind engageHubContactExtras(), so changing them syncs too.
                ...($mapping['watch'] ?? []),
                $mapping['contact'] ?? null,
                $mapping['plan'] ?? null,
            ])));
        }

        if (method_exists($model, 'engageHubWatchedAttributes')) {
            return $model->engageHubWatchedAttributes();
        }

        return null;
    }

    /**
     * Every class registered in engagehub.sync.models — trait-style entries
     * (numeric key) and config mappings (class key) alike.
     *
     * @return list<class-string>
     */
    public function configuredModels(): array
    {
        $models = [];

        foreach ((array) config('engagehub.sync.models') as $key => $value) {
            $models[] = is_int($key) ? $value : $key;
        }

        return $models;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mappingFor(string $class): ?array
    {
        $configured = (array) config('engagehub.sync.models');

        return isset($configured[$class]) && is_array($configured[$class]) ? $configured[$class] : null;
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function mappedPayload(Model $model, array $mapping): array
    {
        $payload = [
            'external_id' => (string) $model->getAttribute($mapping['external_id'] ?? $model->getKeyName()),
        ];

        foreach ($mapping['map'] ?? [] as $engageHubField => $attribute) {
            $payload[$engageHubField] = $model->getAttribute($attribute);
        }

        // 'name' => <full-name attribute> is split into first_name / last_name
        // (an explicit first_name / last_name mapping wins).
        if (array_key_exists('name', $payload)) {
            $name = is_string($payload['name']) ? trim($payload['name']) : '';
            unset($payload['name']);

            if ($name !== '') {
                $parts = preg_split('/\s+/', $name, 2);
                $payload['first_name'] ??= $parts[0];
                $payload['last_name'] ??= $parts[1] ?? null;
            }
        }

        foreach ($mapping['attributes'] ?? [] as $attribute) {
            $payload['attributes'][$attribute] = $model->getAttribute($attribute);
        }

        // Computed fields a column mapping can't express (e.g. derived attributes,
        // consent): the model returns them and they're merged over the mapping.
        if (method_exists($model, 'engageHubContactExtras')) {
            foreach ((array) $model->engageHubContactExtras() as $field => $value) {
                $payload[$field] = is_array($value) && is_array($payload[$field] ?? null)
                    ? array_replace($payload[$field], $value)
                    : $value;
            }
        }

        return $payload + $this->timestamps($model);
    }

    /**
     * The model's created_at / updated_at as ISO 8601. EngageHub uses updated_at
     * to skip an older update that arrives after a newer one (queues reorder).
     *
     * @return array<string, string>
     */
    private function timestamps(Model $model): array
    {
        if (! $model->usesTimestamps()) {
            return [];
        }

        $out = [];

        foreach (['created_at' => $model->getCreatedAtColumn(), 'updated_at' => $model->getUpdatedAtColumn()] as $field => $column) {
            $value = $column !== null ? $model->getAttribute($column) : null;

            if ($value instanceof \DateTimeInterface) {
                $out[$field] = $value->format(\DateTimeInterface::ATOM);
            }
        }

        return $out;
    }

    private function queues(): bool
    {
        return config('engagehub.dispatch', 'queue') === 'queue';
    }

    private function logRejection(string $kind, ItemResult $item): void
    {
        Log::warning("EngageHub rejected {$kind} sync", [
            'identity' => $item->identity,
            'errors' => $item->errors,
        ]);
    }
}
