<?php

namespace EngageHub\Laravel\Mappers;

use EngageHub\Laravel\EngageHubManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Payments from a table that stores Lemon Squeezy webhook bodies as they
 * arrived ({"meta": {"event_name": ...}, "data": {...}}).
 *
 * - An order (order_created) is the first purchase; a subscription invoice
 *   with billing_reason renewal or updated is each later charge. The "initial"
 *   invoice is the same money as its order, so it is skipped.
 * - A refund webhook (order_refunded, subscription_payment_refunded) carries
 *   the whole order or invoice again, so it updates the same payment; partial
 *   refunds keep their amount.
 * - Test-mode events and events that move no money are not payments.
 *
 * Options, under 'lemonsqueezy' in the model's mapping:
 *   'plan'         => ['lookup' => Plan::class, 'by' => 'variant_id']
 *   'subscription' => ['lookup' => Subscription::class, 'by' => 'lemon_squeezy_id', 'variant' => 'variant_id', 'contact' => 'billable_id']
 *   'custom_data'  => keys of meta.custom_data that may hold your user's id (default billable_id, user_id)
 *   'test_mode'    => true to count test-mode events too (default false)
 */
class LemonSqueezyWebhook extends StoredWebhook
{
    private const ORDER_EVENTS = ['order_created', 'order_refunded'];

    private const INVOICE_EVENTS = ['subscription_payment_success', 'subscription_payment_recovered', 'subscription_payment_refunded', 'subscription_payment_failed'];

    public function map(Model $model, array $mapping, EngageHubManager $engagehub): ?array
    {
        $body = $this->body($model, $mapping);
        $options = (array) ($mapping['lemonsqueezy'] ?? []);
        $event = $body['meta']['event_name'] ?? null;
        $data = (array) ($body['data'] ?? []);
        $a = (array) ($data['attributes'] ?? []);

        if (! isset($body['meta'], $data['id'], $a['total']) || (! empty($body['meta']['test_mode']) && empty($options['test_mode']))) {
            return null;
        }

        $kind = match (true) {
            ($data['type'] ?? null) === 'orders' && in_array($event, self::ORDER_EVENTS, true) => 'order',
            ($data['type'] ?? null) === 'subscription-invoices' && in_array($event, self::INVOICE_EVENTS, true)
                && ($a['billing_reason'] ?? null) !== 'initial' => 'invoice',
            default => null,
        };

        $state = strtolower((string) ($a['status'] ?? ''));

        // Not charged yet (or never will be): not money.
        if ($kind === null || in_array($state, ['pending', 'draft', 'void'], true)) {
            return null;
        }

        $subscriptions = (array) ($options['subscription'] ?? []);
        $subscription = $kind === 'invoice' && isset($subscriptions['lookup'], $subscriptions['by'], $a['subscription_id'])
            ? $engagehub->lookup($subscriptions['lookup'], $subscriptions['by'], (string) $a['subscription_id'])
            : null;

        $variant = $kind === 'order'
            ? ($a['first_order_item']['variant_id'] ?? null)
            : $subscription?->getAttribute($subscriptions['variant'] ?? 'variant_id');
        $plan = $variant !== null ? $engagehub->resolve($options['plan'] ?? null, (string) $variant) : null;

        $userId = null;

        foreach ((array) ($options['custom_data'] ?? ['billable_id', 'user_id']) as $key) {
            $userId ??= $body['meta']['custom_data'][$key] ?? null;
        }

        $userId ??= $subscription?->getAttribute($subscriptions['contact'] ?? 'billable_id');
        $contact = $this->contact($model, $mapping, $userId, $a['user_email'] ?? null);

        if ($contact === null) {
            return null;
        }

        $refunded = ! empty($a['refunded']) || in_array($state, ['refunded', 'partial_refund'], true);
        $subscriptionId = $engagehub->externalIdOf($subscription);

        return $this->clean([
            'external_id' => "ls_{$kind}_{$data['id']}",
            'contact' => $contact,
            'plan' => $plan !== null ? ['external_id' => $plan] : null,
            'subscription' => $subscriptionId !== null ? ['external_id' => $subscriptionId] : null,
            'status' => $state === 'failed' ? 'failed' : ($refunded ? 'refunded' : 'paid'),
            'amount_cents' => (int) $a['total'],
            // A partial refund carries its amount; without one the whole payment went back.
            'refunded_cents' => $refunded && ! empty($a['refunded_amount']) ? (int) $a['refunded_amount'] : null,
            'currency' => isset($a['currency']) ? strtoupper((string) $a['currency']) : null,
            'paid_at' => $a['created_at'] ?? null,
            'refunded_at' => $a['refunded_at'] ?? null,
            'updated_at' => $a['updated_at'] ?? $this->timestamp($model, $model->getUpdatedAtColumn()),
            'attributes' => array_filter([
                'source' => 'lemonsqueezy',
                'order_number' => $a['order_number'] ?? null,
                'billing_reason' => $a['billing_reason'] ?? null,
            ], fn (mixed $v): bool => $v !== null),
        ]);
    }
}
