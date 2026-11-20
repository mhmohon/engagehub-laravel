<?php

namespace EngageHub\Laravel\Tests;

use Illuminate\Support\Facades\Queue;
use EngageHub\Laravel\Jobs\DeleteContactFromEngageHub;
use EngageHub\Laravel\Jobs\SyncContactToEngageHub;
use EngageHub\Laravel\Tests\Support\SyncedUser;

class ObserverTest extends TestCase
{
    public function test_creating_a_model_dispatches_contact_sync_with_snapshot(): void
    {
        Queue::fake();

        SyncedUser::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        Queue::assertPushedOn('engagehub', SyncContactToEngageHub::class, function (SyncContactToEngageHub $job): bool {
            return array_diff_key($job->contact, ['created_at' => 1, 'updated_at' => 1]) === [
                'external_id' => '1',
                'email' => 'jane@example.com',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
            ] && preg_match('/^\d{4}-\d{2}-\d{2}T/', $job->contact['updated_at']) === 1;
        });
    }

    public function test_updating_dispatches_again(): void
    {
        Queue::fake();

        $user = SyncedUser::create(['email' => 'jane@example.com']);
        $user->update(['name' => 'Janet']);

        Queue::assertPushed(SyncContactToEngageHub::class, 2);
    }

    public function test_should_sync_gate_blocks_dispatch(): void
    {
        Queue::fake();
        SyncedUser::$syncGate = false;

        SyncedUser::create(['email' => 'jane@example.com']);

        Queue::assertNothingPushed();
    }

    public function test_delete_tells_engagehub_the_user_is_gone(): void
    {
        Queue::fake();

        $user = SyncedUser::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $user->delete();

        Queue::assertPushedOn('engagehub', DeleteContactFromEngageHub::class, fn (DeleteContactFromEngageHub $job): bool => $job->externalId === '1');
    }

    public function test_delete_inline_calls_the_delete_endpoint(): void
    {
        config(['engagehub.dispatch' => 'sync']);
        $this->transport->queueJson(202, ['accepted' => 1, 'created' => 1, 'updated' => 0, 'rejected' => 0, 'results' => []]);
        $this->transport->queueJson(200, ['status' => 'deleted', 'external_id' => '1']);

        SyncedUser::create(['email' => 'jane@example.com'])->delete();

        $this->assertSame('DELETE', $this->transport->requests[1]['method']);
        $this->assertSame('https://engagehub.test/api/v1/contacts/1', $this->transport->requests[1]['url']);
    }

    public function test_delete_with_ignore_behavior_dispatches_nothing(): void
    {
        config(['engagehub.sync.on_delete' => 'ignore']);
        Queue::fake();

        $user = SyncedUser::create(['email' => 'jane@example.com']);
        Queue::assertPushed(SyncContactToEngageHub::class, 1);

        $user->delete();
        Queue::assertNotPushed(DeleteContactFromEngageHub::class);
    }

    public function test_disabled_engagehub_dispatches_nothing(): void
    {
        config(['engagehub.enabled' => false]);
        Queue::fake();

        SyncedUser::create(['email' => 'jane@example.com']);

        Queue::assertNothingPushed();
    }
}
