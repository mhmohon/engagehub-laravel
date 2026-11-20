<?php

namespace EngageHub\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bundle order granted to a user: no amount of its own, the price is the package's. */
class Grant extends Model
{
    protected $guarded = [];

    protected $casts = ['revoked_at' => 'datetime'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
