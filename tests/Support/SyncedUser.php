<?php

namespace EngageHub\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use EngageHub\Laravel\Concerns\SyncsToEngageHub;

class SyncedUser extends Model
{
    use SyncsToEngageHub;

    protected $table = 'users';

    protected $guarded = [];

    public static bool $syncGate = true;

    public function engageHubShouldSync(): bool
    {
        return static::$syncGate;
    }
}
