<?php

namespace EngageHub\Laravel\Mappers;

use EngageHub\Laravel\Contracts\RecordMapper;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for mappers that read a table of stored webhook bodies. Options in the
 * model's mapping:
 *   'payload' => column holding the body (JSON string or cast array); default 'payload'
 *   'contact' => column holding your user's id, when the table has one
 */
abstract class StoredWebhook implements RecordMapper
{
    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    protected function body(Model $model, array $mapping): array
    {
        $body = $model->getAttribute($mapping['payload'] ?? 'payload');

        if (is_string($body)) {
            $body = json_decode($body, true);
        }

        return is_array($body) ? $body : [];
    }

    /**
     * The buyer: your user's id when the row has it, else their email.
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, string>|null
     */
    protected function contact(Model $model, array $mapping, mixed $userId, mixed $email): ?array
    {
        $userId = (isset($mapping['contact']) ? $model->getAttribute($mapping['contact']) : null) ?? $userId;

        if ($userId !== null && $userId !== '') {
            return ['external_id' => (string) $userId];
        }

        $email = strtolower(trim((string) $email));

        return $email !== '' ? ['email' => $email] : null;
    }

    protected function timestamp(Model $model, ?string $column): ?string
    {
        $value = $column !== null ? $model->getAttribute($column) : null;

        return $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function clean(array $record): array
    {
        return array_filter($record, fn (mixed $v): bool => $v !== null && $v !== []);
    }
}
