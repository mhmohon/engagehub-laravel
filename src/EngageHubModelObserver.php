<?php

namespace EngageHub\Laravel;

use Illuminate\Database\Eloquent\Model;

/**
 * Pushes model changes into EngageHub. Registered by the SyncsToEngageHub trait or
 * by the service provider for config-mapped models.
 */
class EngageHubModelObserver
{
    public function __construct(private readonly EngageHubManager $manager) {}

    public function created(Model $model): void
    {
        $this->push($model);
    }

    public function updated(Model $model): void
    {
        $watched = $this->manager->watchedAttributes($model);

        if ($watched !== null && ! $model->wasChanged($watched)) {
            return;
        }

        $this->push($model);
    }

    /**
     * Per config: delete (default) tells EngageHub the user is gone, so they
     * leave the group there; ignore does nothing. A soft delete counts as a
     * delete; restoring the model syncs it again.
     */
    public function deleted(Model $model): void
    {
        if ((string) config('engagehub.sync.on_delete', 'delete') !== 'delete' || ! $this->manager->shouldSync($model)) {
            return;
        }

        $this->manager->remove($model);
    }

    public function restored(Model $model): void
    {
        $this->push($model);
    }

    private function push(Model $model): void
    {
        if (! $this->manager->enabled() || ! $this->manager->shouldSync($model)) {
            return;
        }

        $this->manager->sync($model);
    }
}
