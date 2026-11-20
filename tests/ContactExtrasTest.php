<?php

namespace EngageHub\Laravel\Tests;

use EngageHub\Laravel\Jobs\SyncContactToEngageHub;
use EngageHub\Laravel\Tests\Support\ExtrasUser;
use Illuminate\Support\Facades\Queue;

class ContactExtrasTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('engagehub.sync.models', [
            ExtrasUser::class => [
                'external_id' => 'id',
                'map' => ['email' => 'email'],
                'attributes' => ['role'],
                'watch' => ['plan'],
            ],
        ]);
    }

    public function test_extras_merge_over_the_mapping(): void
    {
        Queue::fake();

        ExtrasUser::create(['email' => 'jane@example.com', 'role' => 'admin', 'plan' => 'pro']);

        Queue::assertPushed(SyncContactToEngageHub::class, function (SyncContactToEngageHub $job): bool {
            return array_diff_key($job->contact, ['created_at' => 1, 'updated_at' => 1]) === [
                'external_id' => '1',
                'email' => 'jane@example.com',
                'attributes' => ['role' => 'admin', 'is_pro' => true],
                'consent' => ['email' => true],
            ];
        });
    }

    public function test_a_watched_column_resyncs(): void
    {
        Queue::fake();

        $user = ExtrasUser::create(['email' => 'jane@example.com']);
        $user->update(['name' => 'Jane']);
        Queue::assertPushed(SyncContactToEngageHub::class, 1);

        $user->update(['plan' => 'opted_out']);
        Queue::assertPushed(SyncContactToEngageHub::class, 2);
    }
}
