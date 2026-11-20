<?php

namespace EngageHub\Laravel\Tests\Support;

use Illuminate\Database\Eloquent\Model;

class PlainUser extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
