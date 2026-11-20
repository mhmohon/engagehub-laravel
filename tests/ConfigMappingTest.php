<?php

namespace EngageHub\Laravel\Tests;

use Illuminate\Support\Facades\Queue;
use EngageHub\Laravel\Jobs\SyncContactToEngageHub;
use EngageHub\Laravel\Tests\Support\PlainUser;

class ConfigMappingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('engagehub.sync.models', [
            PlainUser::class => [
                'external_id' => 'id',
                'map' => ['email' => 'email', 'first_name' => 'name'],
                'attributes' => ['role'],
            ],
        ]);
    }

    public function test_config_mapped_model_syncs_without_the_trait(): void
    {
        Queue::fake();

        PlainUser::create(['name' => 'Jane', 'email' => 'jane@example.com', 'role' => 'admin']);

        Queue::assertPushed(SyncContactToEngageHub::class, function (SyncContactToEngageHub $job): bool {
            return array_diff_key($job->contact, ['created_at' => 1, 'updated_at' => 1]) === [
                'external_id' => '1',
                'email' => 'jane@example.com',
                'first_name' => 'Jane',
                'attributes' => ['role' => 'admin'],
            ];
        });
    }

    public function test_unmapped_attribute_changes_are_skipped(): void
    {
        Queue::fake();

        $user = PlainUser::create(['email' => 'jane@example.com']);
        Queue::assertPushed(SyncContactToEngageHub::class, 1);

        $user->update(['plan' => 'pro']);
        Queue::assertPushed(SyncContactToEngageHub::class, 1);

        $user->update(['role' => 'admin']);
        Queue::assertPushed(SyncContactToEngageHub::class, 2);
    }
}
