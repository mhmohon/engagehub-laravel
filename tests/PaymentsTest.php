<?php

namespace EngageHub\Laravel\Tests;

use EngageHub\Laravel\EngageHubManager;
use EngageHub\Laravel\Jobs\SyncRecordToEngageHub;
use EngageHub\Laravel\Mappers\LemonSqueezyWebhook;
use EngageHub\Laravel\Mappers\WarriorPlusIpn;
use EngageHub\Laravel\Tests\Support\Grant;
use EngageHub\Laravel\Tests\Support\Hook;
use EngageHub\Laravel\Tests\Support\Membership;
use EngageHub\Laravel\Tests\Support\Package;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Payments from config alone: pipes, relation paths, status by dates, and the stored-webhook mappers. */
class PaymentsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('engagehub.sync.models', [
            Package::class => ['as' => 'plan', 'map' => ['name' => 'title', 'amount_cents' => 'price|cents']],
            Membership::class => ['as' => 'subscription', 'contact' => 'member_id', 'map' => ['status' => 'state']],
            Grant::class => [
                'as' => 'payment',
                'external_id' => 'order_id|prefix:bundle_',
                'contact' => 'user_id',
                'plan' => 'package_id',
                'map' => ['amount_cents' => 'package.price|cents|divide:4', 'paid_at' => 'created_at', 'refunded_at' => 'revoked_at'],
                'defaults' => ['currency' => 'USD'],
            ],
            Hook::class => [
                'as' => 'payment',
                'using' => [LemonSqueezyWebhook::class, WarriorPlusIpn::class],
                'contact' => 'user_id',
                'lemonsqueezy' => [
                    'plan' => ['lookup' => Package::class, 'by' => 'code'],
                    'subscription' => ['lookup' => Membership::class, 'by' => 'remote_id', 'variant' => 'code', 'contact' => 'member_id'],
                ],
                'warriorplus' => ['plan' => ['lookup' => Package::class, 'by' => 'code']],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('packages', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->string('code')->nullable();
            $t->decimal('price', 8, 2)->nullable();
        });
        Schema::create('memberships', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('member_id');
            $t->string('remote_id')->nullable();
            $t->string('code')->nullable();
            $t->string('state')->default('active');
            $t->timestamps();
        });
        Schema::create('grants', function (Blueprint $t): void {
            $t->id();
            $t->string('order_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('package_id')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('hooks', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->json('payload');
            $t->timestamps();
        });

        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach (['packages', 'memberships', 'grants', 'hooks'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function record(object $model): array
    {
        return $this->app->make(EngageHubManager::class)->recordPayload($model);
    }

    public function test_pipes_turn_a_decimal_price_into_cents(): void
    {
        $this->assertSame(1999, $this->record(Package::create(['title' => 'Pro', 'price' => '19.99']))['amount_cents']);
        $this->assertNull($this->record(Package::create(['title' => 'Free']))['amount_cents']);
    }

    public function test_an_unknown_pipe_is_a_config_error(): void
    {
        config()->set('engagehub.sync.models.'.Package::class.'.map.name', 'title|shout');

        $this->expectException(InvalidArgumentException::class);

        $this->record(new Package(['title' => 'Pro']));
    }

    public function test_a_payment_takes_its_price_from_a_relation_and_its_status_from_its_dates(): void
    {
        $package = Package::create(['title' => 'Bundle', 'price' => '179.00']);
        $grant = Grant::create(['order_id' => 'A1', 'user_id' => 7, 'package_id' => $package->id]);

        $paid = $this->record($grant);
        $this->assertSame('bundle_A1', $paid['external_id']);
        $this->assertSame(4475, $paid['amount_cents'], '$179 paid once for 4 apps');
        $this->assertSame('paid', $paid['status']);
        $this->assertSame('USD', $paid['currency']);
        $this->assertSame(['external_id' => '7'], $paid['contact']);
        $this->assertSame(['external_id' => (string) $package->id], $paid['plan']);

        $grant->update(['revoked_at' => now()]);
        $this->assertSame('refunded', $this->record($grant->fresh())['status']);
    }

    public function test_a_payment_with_no_amount_is_not_sent(): void
    {
        Grant::create(['order_id' => 'A2', 'user_id' => 7]);

        Queue::assertNotPushed(SyncRecordToEngageHub::class, fn ($job) => $job->kind === 'payment');
    }

    public function test_lemon_squeezy_orders_renewals_and_refunds_become_payments(): void
    {
        Package::create(['title' => 'Pro', 'code' => '555', 'price' => '19.00']);
        $membership = Membership::create(['member_id' => 9, 'remote_id' => '77', 'code' => '555']);

        $order = fn (array $a = [], string $event = 'order_created') => ['meta' => ['event_name' => $event, 'custom_data' => ['billable_id' => '9']], 'data' => ['type' => 'orders', 'id' => '1001', 'attributes' => $a + [
            'status' => 'paid', 'total' => 1900, 'currency' => 'USD', 'user_email' => 'Jane@Example.com', 'order_number' => 12,
            'first_order_item' => ['variant_id' => 555], 'created_at' => '2026-11-01T10:00:00.000000Z', 'updated_at' => '2026-11-01T10:00:00.000000Z',
        ]]];
        $invoice = fn (string $reason) => ['meta' => ['event_name' => 'subscription_payment_success'], 'data' => ['type' => 'subscription-invoices', 'id' => '2002', 'attributes' => [
            'status' => 'paid', 'total' => 1900, 'currency' => 'USD', 'billing_reason' => $reason, 'subscription_id' => 77, 'user_email' => 'jane@example.com',
        ]]];

        $paid = $this->record(Hook::create(['payload' => $order()]));
        $this->assertSame('ls_order_1001', $paid['external_id']);
        $this->assertSame(['external_id' => '9'], $paid['contact']);
        $this->assertSame(['external_id' => '1'], $paid['plan']);
        $this->assertSame(['paid', 1900, 'USD'], [$paid['status'], $paid['amount_cents'], $paid['currency']]);

        $refund = $this->record(Hook::create(['payload' => $order(['status' => 'partial_refund', 'refunded' => true, 'refunded_amount' => 500, 'refunded_at' => '2026-11-03T10:00:00.000000Z'], 'order_refunded')]));
        $this->assertSame(['ls_order_1001', 'refunded', 500], [$refund['external_id'], $refund['status'], $refund['refunded_cents']]);

        $renewal = $this->record(Hook::create(['payload' => $invoice('renewal')]));
        $this->assertSame('ls_invoice_2002', $renewal['external_id']);
        $this->assertSame(['external_id' => '9'], $renewal['contact'], 'from the subscription when the webhook has no custom data');
        $this->assertSame(['external_id' => (string) $membership->id], $renewal['subscription']);
        $this->assertSame(['external_id' => '1'], $renewal['plan']);

        // Same money as the order, a test purchase, and an event that moved no money: not payments.
        $this->assertSame([], $this->record(Hook::create(['payload' => $invoice('initial')])));
        $this->assertSame([], $this->record(Hook::create(['payload' => ['meta' => ['test_mode' => true] + $order()['meta'], 'data' => $order()['data']]])));
        $this->assertSame([], $this->record(Hook::create(['payload' => ['meta' => ['event_name' => 'subscription_created'], 'data' => ['type' => 'subscriptions', 'id' => '77', 'attributes' => []]]])));

        $this->assertCount(3, Queue::pushed(SyncRecordToEngageHub::class, fn ($job) => $job->kind === 'payment'), 'only the three payments were sent');
    }

    public function test_warriorplus_ipns_become_payments(): void
    {
        Package::create(['title' => 'Front end', 'code' => 'wso_abc']);

        $sale = $this->record(Hook::create(['payload' => ['WP_ACTION' => 'sale', 'WP_SALE' => 'S1', 'WP_TXNID' => 'T1', 'WP_SALE_AMOUNT' => '47.00', 'WP_BUYER_EMAIL' => 'Buyer@Example.com', 'WP_ITEM_NUMBER' => 'wso_abc']]));
        $this->assertSame(['wp_S1', 'paid', 4700, 'USD'], [$sale['external_id'], $sale['status'], $sale['amount_cents'], $sale['currency']]);
        $this->assertSame(['email' => 'buyer@example.com'], $sale['contact']);
        $this->assertSame(['external_id' => '1'], $sale['plan']);

        $refund = $this->record(Hook::create(['user_id' => 4, 'payload' => ['WP_ACTION' => 'refund', 'WP_SALE' => 'S1', 'WP_SALE_AMOUNT' => '47.00', 'WP_BUYER_EMAIL' => 'buyer@example.com']]));
        $this->assertSame(['wp_S1', 'refunded'], [$refund['external_id'], $refund['status']]);
        $this->assertSame(['external_id' => '4'], $refund['contact']);
        $this->assertArrayNotHasKey('paid_at', $refund);

        $this->assertSame([], $this->record(Hook::create(['payload' => ['WP_ACTION' => 'subscr_cancelled', 'WP_SALE' => 'S1', 'WP_BUYER_EMAIL' => 'buyer@example.com']])));
    }
}
