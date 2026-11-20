<?php

namespace EngageHub\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    protected $guarded = [];

    protected $casts = ['renews_on' => 'datetime'];
}
