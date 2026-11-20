<?php

namespace EngageHub\Laravel\Tests;

use EngageHub\Laravel\Jobs\SyncContactToEngageHub;
use EngageHub\Laravel\Tests\Support\PlainUser;
use Illuminate\Support\Facades\Queue;

class NameSplitMappingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('engagehub.sync.models', [
            PlainUser::class => [
                'external_id' => 'id',
                'map' => ['email' => 'email', 'name' => 'name'],
            ],
        ]);
    }

    public function test_a_mapped_full_name_is_split_into_first_and_last(): void
    {
        Queue::fake();

        PlainUser::create(['name' => '  Fidel  Fisher PhD ', 'email' => 'fidel@example.com']);

        Queue::assertPushed(SyncContactToEngageHub::class, fn (SyncContactToEngageHub $job): bool => array_diff_key($job->contact, ['created_at' => 1, 'updated_at' => 1]) === [
            'external_id' => '1',
            'email' => 'fidel@example.com',
            'first_name' => 'Fidel',
            'last_name' => 'Fisher PhD',
        ]);
    }

    public function test_a_single_word_name_clears_the_last_name(): void
    {
        Queue::fake();

        PlainUser::create(['name' => 'Cher', 'email' => 'cher@example.com']);

        Queue::assertPushed(SyncContactToEngageHub::class, fn (SyncContactToEngageHub $job): bool => $job->contact['first_name'] === 'Cher'
            && array_key_exists('last_name', $job->contact) && $job->contact['last_name'] === null
            && ! array_key_exists('name', $job->contact));
    }
}
