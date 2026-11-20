<?php

namespace EngageHub\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use EngageHub\Laravel\EngageHubManager;

/**
 * @method static void track(string $name, \Illuminate\Database\Eloquent\Model|array $identity = [], array $properties = [])
 * @method static void forget(\Illuminate\Database\Eloquent\Model|string $contact)
 * @method static void identify(\Illuminate\Database\Eloquent\Model|array $contact)
 * @method static array status()
 * @method static bool enabled()
 * @method static \EngageHub\EngageHubClient client()
 *
 * @see EngageHubManager
 */
class EngageHub extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EngageHubManager::class;
    }
}
