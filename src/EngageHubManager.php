<?php

namespace EngageHub\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use EngageHub\Dto\ItemResult;
use EngageHub\Laravel\Contracts\RecordMapper;
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

        $payload = $this->recordPayload($model);

        // Not a record (a webhook that moved no money, a payment with no amount).
        if (($payload['external_id'] ?? '') === '') {
            return;
        }

        $this->dispatchRecord($kind, $payload);
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

        $mapping = $this->mappingFor($model::class) ?? [];

        if (isset($mapping['using'])) {
            return $this->mapWith($model, $mapping);
        }

        $payload = $this->mappedRecord($model, $mapping, $kind);

        // A payment whose amount is unknown is not sent: nothing is estimated.
        return $kind === 'payment' && ! isset($payload['amount_cents']) ? [] : $payload;
    }

    /**
     * 'using' => mapper class(es) for rows that need more than a column map
     * (a stored webhook body, ...). The first mapper that recognises the row
     * wins; a row none recognises is not a record and is not sent.
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function mapWith(Model $model, array $mapping): array
    {
        foreach ((array) $mapping['using'] as $class) {
            $mapper = app($class);

            if (! $mapper instanceof RecordMapper) {
                throw new InvalidArgumentException("{$class} must implement ".RecordMapper::class.'.');
            }

            if (($record = $mapper->map($model, $mapping, $this)) !== null) {
                return $record;
            }
        }

        return [];
    }

    /** The row of $class whose $by column holds $value, for mappers and lookups. */
    public function lookup(string $class, string $by, mixed $value): ?Model
    {
        if ($value === null || $value === '' || ! is_a($class, Model::class, true)) {
            return null;
        }

        // Compare as text: MySQL turns `varchar_col = 0` into a numeric comparison where every
        // non-numeric string equals 0, so an integer 0 would match the first plan with a text code.
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return $class::query()->where($by, $value)->first();
    }

    /** The EngageHub external id of a synced model (per its own mapping), or null. */
    public function externalIdOf(?Model $model): ?string
    {
        $id = $model !== null ? ($this->recordPayload($model)['external_id'] ?? null) : null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    /**
     * Resolve a ['lookup' => Model::class, 'by' => column] option against a
     * value from elsewhere (a webhook body): the matching row's external id.
     *
     * @param  array<string, mixed>|null  $option
     */
    public function resolve(?array $option, mixed $value): ?string
    {
        return isset($option['lookup'], $option['by']) ? $this->externalIdOf($this->lookup($option['lookup'], $option['by'], $value)) : null;
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
     * The external id a reference points at: an attribute holding it, or a
     * lookup (['from' => column here, 'lookup' => Model::class, 'by' => column
     * there]) that finds the related row and uses its EngageHub external id.
     *
     * A list of references is tried in order and the first that finds a row wins; a reference with
     * 'when' => [attribute => value or list of values] is only tried on rows that match it, for
     * tables where rows of different origins point at the plan in different columns.
     */
    private function reference(Model $model, mixed $ref): ?string
    {
        $candidates = is_array($ref) && array_is_list($ref) ? $ref : [$ref];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate['when'])) {
                if (! $this->matchesOnly($model, (array) $candidate['when'])) {
                    continue;
                }

                unset($candidate['when']);
                $candidate = $candidate['attribute'] ?? $candidate;
            }

            if (($found = $this->singleReference($model, $candidate)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /** One reference: an attribute name, or a ['from', 'lookup', 'by'] lookup. */
    private function singleReference(Model $model, mixed $ref): ?string
    {
        if (is_string($ref)) {
            $value = $model->getAttribute($ref);

            return $value === null || $value === '' ? null : (string) $value;
        }

        if (! is_array($ref) || ! isset($ref['from'], $ref['lookup']) || ! is_a($ref['lookup'], Model::class, true)) {
            throw new InvalidArgumentException('A reference is an attribute name or [\'from\' => ..., \'lookup\' => Model::class, \'by\' => ...].');
        }

        $value = $model->getAttribute($ref['from']);

        if ($value === null || $value === '') {
            return null;
        }

        return $this->externalIdOf($this->lookup($ref['lookup'], $ref['by'] ?? $ref['from'], $value));
    }

    /**
     * A map source: an attribute, a path through relations ("plan.amount"),
     * and optional pipes: "plan.amount|cents|divide:4".
     *   cents      a decimal amount (19.99) to cents (1999)
     *   divide:n   split by n (an order that paid for n apps), rounded
     *   multiply:n
     *   prefix:x   put x in front ("order_id|prefix:darkcore_")
     *   upper, lower
     *
     * @param  array<string, mixed>  $values  your value => EngageHub's, applied before the pipes
     */
    private function value(Model $model, string $source, array $values = []): mixed
    {
        $pipes = explode('|', $source);
        $path = (string) array_shift($pipes);
        $value = str_contains($path, '.') ? data_get($model, $path) : $model->getAttribute($path);

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(\DateTimeInterface::ATOM);
        } elseif ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (is_scalar($value) && isset($values[(string) $value])) {
            $value = $values[(string) $value];
        }

        foreach ($pipes as $pipe) {
            if ($value === null) {
                break;
            }

            [$name, $argument] = explode(':', $pipe, 2) + [1 => null];

            // divide:app_count / multiply:quantity: a non-numeric argument is the model's attribute.
            if (in_array($name, ['divide', 'multiply'], true) && $argument !== null && ! is_numeric($argument)) {
                $argument = data_get($model, $argument);
                $argument = is_numeric($argument) ? (string) $argument : null;
            }

            $value = match ($name) {
                'cents' => is_numeric($value) ? (int) round(((float) $value) * 100) : $value,
                'divide' => is_numeric($value) && is_numeric($argument) && (float) $argument != 0.0 ? (int) round(((float) $value) / (float) $argument) : $value,
                'multiply' => is_numeric($value) && is_numeric($argument) ? (int) round(((float) $value) * (float) $argument) : $value,
                'prefix' => $argument.$value,
                'upper' => is_string($value) ? strtoupper($value) : $value,
                'lower' => is_string($value) ? strtolower($value) : $value,
                default => throw new InvalidArgumentException("Unknown pipe \"{$name}\" in \"{$source}\". Pipes: cents, divide:n, multiply:n, prefix:x, upper, lower."),
            };
        }

        return $value;
    }

    /**
     * Config mapping for plans, subscriptions and objects:
     *   'external_id' => 'id', 'map' => [engagehub field => attribute],
     *   'values' => [engagehub field => [your value => engagehub value]],
     *   'contact' => attribute holding the user's id, 'plan' => attribute holding the plan's id,
     *   'defaults' => [engagehub field => fixed value] for fields with no column,
     *   map sources may follow relations and take pipes: 'plan.amount|cents|divide:4',
     *   and divide / multiply by another attribute: 'amount_cents|divide:app_count',
     *   or a lookup when the model points at it by another column:
     *   'plan' => ['from' => 'variant_id', 'lookup' => Plan::class, 'by' => 'variant_id'],
     *   or a list tried in order, each optionally limited to some rows with 'when':
     *   'plan' => [
     *       ['when' => ['type' => 'warriorplus'], 'from' => 'product_id', 'lookup' => Plan::class, 'by' => 'id'],
     *       ['from' => 'variant_id', 'lookup' => Plan::class, 'by' => 'variant_id'],
     *   ],
     *   'attributes' => [attribute, ...]
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function mappedRecord(Model $model, array $mapping, string $kind): array
    {
        $payload = ['external_id' => (string) $this->value($model, $mapping['external_id'] ?? $model->getKeyName())];

        foreach ($mapping['map'] ?? [] as $field => $source) {
            $payload[$field] = $this->value($model, $source, (array) ($mapping['values'][$field] ?? []));
        }

        // 'defaults' => fixed values for fields the table has no column for (a currency, ...).
        foreach ((array) ($mapping['defaults'] ?? []) as $field => $default) {
            $payload[$field] ??= $default;
        }

        // A payment says its status by its dates unless 'status' is mapped:
        // refunded once it has a refund date, paid otherwise.
        if ($kind === 'payment' && ! isset($payload['status'])) {
            $payload['status'] = empty($payload['refunded_at']) ? 'paid' : 'refunded';
        }

        if (isset($mapping['contact']) && ($owner = $this->reference($model, $mapping['contact'])) !== null) {
            $payload['contact'] = ['external_id' => $owner];
        }

        if (in_array($kind, ['subscription', 'payment'], true) && isset($mapping['plan']) && ($plan = $this->reference($model, $mapping['plan'])) !== null) {
            $payload['plan'] = ['external_id' => $plan];
        }

        if ($kind === 'payment' && isset($mapping['subscription']) && ($subscription = $this->reference($model, $mapping['subscription'])) !== null) {
            $payload['subscription'] = ['external_id' => $subscription];
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
     * The meters in engagehub.sync.meters, as EngageHub takes them.
     *
     * @return list<array{used: string, limit: string|null, label: string}>
     */
    public function meters(): array
    {
        $meters = [];

        foreach ((array) config('engagehub.sync.meters', []) as $name => $meter) {
            $meter = is_string($meter) ? ['used' => $meter] : (array) $meter;

            if (! isset($meter['used']) || ! is_string($meter['used']) || $meter['used'] === '') {
                throw new InvalidArgumentException("engagehub.sync.meters.{$name} needs 'used': the attribute counting usage.");
            }

            $meters[] = [
                'used' => $meter['used'],
                'limit' => isset($meter['limit']) && is_string($meter['limit']) && $meter['limit'] !== '' ? $meter['limit'] : null,
                'label' => (string) ($meter['label'] ?? Str::headline((string) $name)),
            ];
        }

        return $meters;
    }

    /**
     * Send the app's schema (meters) to EngageHub when it changed since the
     * last send, or always with $force. An app that never declared anything
     * sends nothing. Returns whether it was sent.
     */
    public function describe(bool $force = false): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $meters = $this->meters();
        $hash = sha1((string) json_encode($meters));
        $key = 'engagehub:schema:'.substr(sha1((string) config('engagehub.api_key').'|'.config('engagehub.base_url')), 0, 16);
        $sent = Cache::get($key);

        if (($sent === null && $meters === []) || (! $force && $sent === $hash)) {
            return false;
        }

        $this->client->describe(['meters' => $meters]);
        Cache::forever($key, $hash);

        return true;
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
        if (! $this->matchesOnly($model, (array) ($this->mappingFor($model::class)['only'] ?? []))) {
            return false;
        }

        return method_exists($model, 'engageHubShouldSync') ? $model->engageHubShouldSync() : true;
    }

    /**
     * 'only' => [attribute => value or list of values]: rows that don't match every entry are not
     * synced (a pending order, a test row), from config instead of engageHubShouldSync().
     *
     * @param  array<string, mixed>  $only
     */
    private function matchesOnly(Model $model, array $only): bool
    {
        foreach ($only as $attribute => $allowed) {
            $value = $this->value($model, (string) $attribute);
            $value = is_bool($value) ? (int) $value : $value;

            $matches = array_filter(
                is_array($allowed) ? $allowed : [$allowed],
                fn (mixed $want): bool => $want === null ? $value === null : $value !== null && (string) (is_bool($want) ? (int) $want : $want) === (string) $value,
            );

            if ($matches === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Model attributes whose change warrants a sync; null = any change.
     *
     * @return list<string>|null
     */
    public function watchedAttributes(Model $model): ?array
    {
        $mapping = $this->mappingFor($model::class);

        // A mapper reads what it likes: any change counts.
        if ($mapping !== null && isset($mapping['using'])) {
            return null;
        }

        if ($mapping !== null) {
            // "plan.amount|cents" watches nothing here (it is the plan's); "amount|cents" watches amount.
            $column = fn (string $source): ?string => str_contains($path = explode('|', $source)[0], '.') ? null : $path;

            return array_values(array_unique(array_filter([
                $column($mapping['external_id'] ?? $model->getKeyName()),
                ...array_map($column, array_values($mapping['map'] ?? [])),
                ...($mapping['attributes'] ?? []),
                // Columns behind engageHubContactExtras(), so changing them syncs too.
                ...($mapping['watch'] ?? []),
                // Rows can move into 'only' (an order that gets paid) and the opt-out column.
                ...array_map('strval', array_keys((array) ($mapping['only'] ?? []))),
                ...(isset($mapping['unsubscribed']) ? [(string) $mapping['unsubscribed']] : []),
                ...array_map(
                    fn (mixed $ref) => is_array($ref) ? ($ref['from'] ?? null) : $ref,
                    array_values(array_intersect_key($mapping, array_flip(['contact', 'plan', 'subscription']))),
                ),
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

        // 'unsubscribed' => the app's marketing opt-out column (a flag or a date): when it is set,
        // the contact's email consent is off. A sync only takes consent away, never gives it back.
        if (isset($mapping['unsubscribed'])) {
            $optedOut = $model->getAttribute((string) $mapping['unsubscribed']);

            if ($optedOut !== null && $optedOut !== false && $optedOut !== 0 && $optedOut !== '0' && $optedOut !== '') {
                $payload['consent'] = ['email' => false];
            }
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
