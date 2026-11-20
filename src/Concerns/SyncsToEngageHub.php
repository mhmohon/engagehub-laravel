<?php

namespace EngageHub\Laravel\Concerns;

use EngageHub\Laravel\EngageHubManager;
use EngageHub\Laravel\EngageHubModelObserver;

/**
 * Add to any Eloquent model to mirror it into EngageHub as a contact on
 * created/updated/deleted (and restored, with SoftDeletes). Override toEngageHubContact() to control the field
 * mapping, engageHubShouldSync() to gate rows, and engageHubWatchedAttributes()
 * to limit which attribute changes trigger a sync.
 *
 * Also list the model in config('engagehub.sync.models') so the engagehub:sync
 * backfill command can find it.
 */
trait SyncsToEngageHub
{
    public static function bootSyncsToEngageHub(): void
    {
        // Direct hook registration instead of static::observe(): observe()
        // instantiates the model, which Laravel forbids mid-boot.
        static::created(static fn ($model) => app(EngageHubModelObserver::class)->created($model));
        static::updated(static fn ($model) => app(EngageHubModelObserver::class)->updated($model));
        static::deleted(static fn ($model) => app(EngageHubModelObserver::class)->deleted($model));

        if (method_exists(static::class, 'restored')) {
            static::restored(static fn ($model) => app(EngageHubModelObserver::class)->restored($model));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toEngageHubContact(): array
    {
        return app(EngageHubManager::class)->defaultContactPayload($this);
    }

    public function engageHubShouldSync(): bool
    {
        return true;
    }
}
