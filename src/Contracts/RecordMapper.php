<?php

namespace EngageHub\Laravel\Contracts;

use EngageHub\Laravel\EngageHubManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns a row into an EngageHub record when a column map is not enough, e.g.
 * a table of stored webhook bodies. Named in config: 'using' => [Mapper::class].
 */
interface RecordMapper
{
    /**
     * @param  array<string, mixed>  $mapping  the model's entry in engagehub.sync.models
     * @return array<string, mixed>|null the record, or null when this row is not one this mapper knows
     */
    public function map(Model $model, array $mapping, EngageHubManager $engagehub): ?array;
}
