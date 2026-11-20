<?php

namespace EngageHub\Laravel\Tests;

use EngageHub\Laravel\Jobs\DeleteRecordFromEngageHub;
use EngageHub\Laravel\Jobs\SyncRecordToEngageHub;
use EngageHub\Laravel\Tests\Support\Book;
use EngageHub\Laravel\Tests\Support\Membership;
use EngageHub\Laravel\Tests\Support\Package;
use EngageHub\Laravel\Tests\Support\PlainUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/** Plans, subscriptions and objects mapped in config (`'as' => ...`). */
class RecordKindsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('engagehub.sync.models', [
            Membership::class => [
                'as' => 'subscription',
                'contact' => 'member_id',
                'plan' => 'package_id',
                'map' => ['status' => 'state', 'current_period_ends_at' => 'renews_on'],
                'values' => ['status' => ['live' => 'active', 'stopped' => 'canceled']],
                'attributes' => ['seats'],
            ],
            Book::class => ['as' => 'object:book', 'contact' => 'user_id', 'map' => ['name' => 'title'], 'attributes' => ['chapters_count']],
            Package::class => ['as' => 'plan', 'map' => ['name' => 'title', 'amount_cents' => 'price_cents']],
            PlainUser::class => ['map' => ['email' => 'email']],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('memberships', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('member_id');
            $t->unsignedBigInteger('package_id')->nullable();
            $t->string('state');
            $t->timestamp('renews_on')->nullable();
            $t->unsignedInteger('seats')->default(1);
            $t->timestamps();
        });
        Schema::create('packages', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->unsignedInteger('price_cents')->default(0);
        });
        Schema::create('books', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('title');
            $t->unsignedInteger('chapters_count')->default(0);
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('books');

        parent::tearDown();
    }

    public function test_a_subscription_model_is_mapped_with_contact_plan_and_value_maps(): void
    {
        Queue::fake();

        Membership::create(['member_id' => 42, 'package_id' => 7, 'state' => 'live', 'renews_on' => '2026-12-01 00:00:00', 'seats' => 3]);

        Queue::assertPushed(SyncRecordToEngageHub::class, function (SyncRecordToEngageHub $job): bool {
            return $job->kind === 'subscription'
                && $job->record['external_id'] === '1'
                && $job->record['contact'] === ['external_id' => '42']
                && $job->record['plan'] === ['external_id' => '7']
                && $job->record['status'] === 'active'
                && str_starts_with($job->record['current_period_ends_at'], '2026-12-01T00:00:00')
                && $job->record['attributes'] === ['seats' => 3]
                && isset($job->record['updated_at'])
                && ! isset($job->record['created_at']);
        });
    }

    public function test_objects_and_plans_go_to_their_endpoints_inline(): void
    {
        config(['engagehub.dispatch' => 'sync']);
        $this->transport->queueJson(202, ['accepted' => 1, 'created' => 1, 'updated' => 0, 'rejected' => 0, 'results' => [['index' => 0, 'status' => 'created']]]);

        Book::create(['user_id' => 42, 'title' => 'My book', 'chapters_count' => 3]);
        Package::create(['title' => 'Pro', 'price_cents' => 1900]);

        $this->assertSame('https://engagehub.test/api/v1/objects/book/sync', $this->transport->requests[0]['url']);
        $book = json_decode($this->transport->requests[0]['body'], true);
        $this->assertSame(['external_id' => '1', 'name' => 'My book', 'contact' => ['external_id' => '42'], 'attributes' => ['chapters_count' => 3]], array_diff_key($book, ['created_at' => 1, 'updated_at' => 1]));

        $this->assertSame('https://engagehub.test/api/v1/plans/sync', $this->transport->requests[1]['url']);
        $this->assertSame(['external_id' => '1', 'name' => 'Pro', 'amount_cents' => 1900], json_decode($this->transport->requests[1]['body'], true));
    }

    public function test_deleting_records_deletes_subscriptions_and_objects_and_retires_plans(): void
    {
        Queue::fake();

        Membership::create(['member_id' => 42, 'state' => 'live'])->delete();
        Book::create(['user_id' => 42, 'title' => 'B'])->delete();
        Package::create(['title' => 'Old'])->delete();

        Queue::assertPushed(DeleteRecordFromEngageHub::class, fn ($job) => $job->kind === 'subscription' && $job->externalId === '1');
        Queue::assertPushed(DeleteRecordFromEngageHub::class, fn ($job) => $job->kind === 'object:book' && $job->externalId === '1');
        Queue::assertPushed(SyncRecordToEngageHub::class, fn ($job) => $job->kind === 'plan' && $job->record === ['external_id' => '1', 'active' => false]);
    }

    public function test_the_backfill_sends_plans_and_people_first_in_backfill_mode(): void
    {
        config(['engagehub.enabled' => false]);
        PlainUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        Package::create(['title' => 'Pro']);
        Membership::create(['member_id' => 1, 'package_id' => 1, 'state' => 'live']);
        Book::create(['user_id' => 1, 'title' => 'B']);
        config(['engagehub.enabled' => true]);

        $this->transport->queueJson(202, ['accepted' => 1, 'created' => 1, 'updated' => 0, 'rejected' => 0, 'results' => []]);

        $this->artisan('engagehub:sync', ['--rpm' => 0])->assertSuccessful();

        $this->assertSame([
            'https://engagehub.test/api/v1/plans/sync',
            'https://engagehub.test/api/v1/contacts/sync',
            'https://engagehub.test/api/v1/subscriptions/sync',
            'https://engagehub.test/api/v1/objects/book/sync',
        ], array_column($this->transport->requests, 'url'));

        foreach ($this->transport->requests as $request) {
            $this->assertSame('backfill', json_decode($request['body'], true)['mode']);
        }
    }

    public function test_an_unknown_kind_is_a_clear_error(): void
    {
        config(['engagehub.sync.models' => [Book::class => ['as' => 'books']]]);

        $this->expectExceptionMessage('unknown kind "books"');

        app(\EngageHub\Laravel\EngageHubManager::class)->kindOf(Book::class);
    }
}
