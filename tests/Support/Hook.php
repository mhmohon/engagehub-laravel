<?php

namespace EngageHub\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** A stored webhook body, as apps log them. */
class Hook extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'json'];
}
