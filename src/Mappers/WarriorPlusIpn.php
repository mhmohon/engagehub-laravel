<?php

namespace EngageHub\Laravel\Mappers;

use EngageHub\Laravel\EngageHubManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Payments from a table that stores WarriorPlus IPNs as they arrived
 * ({"WP_ACTION": "sale", "WP_SALE": ..., "WP_SALE_AMOUNT": ...}).
 *
 * Uses the ids EngageHub's own WarriorPlus sales webhook uses (wp_<sale>), so
 * a sale that reaches EngageHub both ways is counted once. A renewal
 * (subscr_completed) is its own payment; a refund points back at its sale.
 * The IPN has no time of its own, so the row's created_at is when it happened.
 *
 * Options, under 'warriorplus' in the model's mapping:
 *   'plan' => ['lookup' => Plan::class, 'by' => 'wplus_code']   matched against WP_ITEM_NUMBER
 */
class WarriorPlusIpn extends StoredWebhook
{
    private const SALE = ['sale', 'subscr_created', 'subscr_completed', 'subscr_reactivated'];

    private const REFUND = ['refund', 'subscr_refunded'];

    public function map(Model $model, array $mapping, EngageHubManager $engagehub): ?array
    {
        $ipn = $this->body($model, $mapping);

        if (! isset($ipn['WP_ACTION'])) {
            return null;
        }

        $action = strtolower(trim((string) $ipn['WP_ACTION']));
        $refund = in_array($action, self::REFUND, true);

        if (! $refund && ! in_array($action, self::SALE, true)) {
            return null;
        }

        $reference = $action === 'subscr_completed'
            ? ($ipn['WP_TXNID'] ?? $ipn['WP_SALE'] ?? null)
            : (($ipn['WP_SALE'] ?? null) ?: ($ipn['WP_TXNID'] ?? null));
        $contact = $this->contact($model, $mapping, null, $ipn['WP_BUYER_EMAIL'] ?? null);

        if ($reference === null || $reference === '' || $contact === null) {
            return null;
        }

        $at = $this->timestamp($model, $model->getCreatedAtColumn());
        $plan = isset($ipn['WP_ITEM_NUMBER']) ? $engagehub->resolve($mapping['warriorplus']['plan'] ?? null, (string) $ipn['WP_ITEM_NUMBER']) : null;

        return $this->clean([
            'external_id' => 'wp_'.$reference,
            'contact' => $contact,
            'plan' => $plan !== null ? ['external_id' => $plan] : null,
            'status' => $refund ? 'refunded' : 'paid',
            'amount_cents' => (int) round(((float) ($ipn['WP_SALE_AMOUNT'] ?? 0)) * 100),
            'currency' => strtoupper((string) ($ipn['WP_SALE_CURRENCY'] ?? 'USD')) ?: 'USD',
            'paid_at' => $refund ? null : $at,
            'refunded_at' => $refund ? $at : null,
            'updated_at' => $this->timestamp($model, $model->getUpdatedAtColumn()),
            'attributes' => array_filter([
                'source' => 'warriorplus',
                'product' => $ipn['WP_ITEM_NAME'] ?? null,
                'product_id' => $ipn['WP_ITEM_NUMBER'] ?? null,
            ], fn (mixed $v): bool => $v !== null),
        ]);
    }
}
