<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model;

/**
 * Pure rate-formula calculator. Stateless. Magento-free.
 *
 * Given a rate row's four components and the cart context, computes the
 * final shipping cost and applies the method's min/max clamp. Lives as
 * its own class (separate from RateMatcher) so the formula can be:
 *
 *  - Unit-tested in isolation against every component combination
 *  - Re-used by the admin live-cart simulator to show the cost breakdown
 *    step-by-step ("base $5 + per-product $2 × 3 + per-kg $1 × 2.5 = ...")
 *  - Easily extended later for volumetric weight without touching the
 *    matching logic
 *
 * Formula:
 *
 *    billingWeight = weight / weight_unit_conversion_rate    (default factor 1.0)
 *
 *    rawCost = rate_base
 *            + (rate_per_product × qty)
 *            + (rate_per_kg     × billingWeight)
 *            + (rate_percent    × subtotal / 100)
 *
 *    finalCost = clamp(rawCost, method.min_rate, method.max_rate)
 *
 * Where a min/max is NULL it's treated as "no clamp on that side". Negative
 * rate components are accepted (e.g. -10 base for a promotional method)
 * but the final cost is never returned below 0 — Magento can't quote
 * negative shipping.
 *
 * The conversion factor matches Amasty's "Weight Unit Conversion Rate" field
 * — same semantics: cart_weight DIVIDED by the factor. Example values:
 *   - 1.0 (default) — no conversion
 *   - 2.2046 — cart weight in lbs, billed in kg (10 lbs / 2.2046 = 4.54 kg)
 *   - 0.4536 — cart weight in kg, billed in lbs
 */
class RateCalculator
{
    /**
     * Calculate the final shipping cost.
     *
     * Components and clamps default to permissive values so callers don't
     * have to pass nullable everywhere — pass 0.0 for "no charge" and
     * null for "no clamp".
     *
     * @param CartContext $context              Cart state — provides qty / weight / subtotal
     * @param float       $base                 rate_base — flat charge added once per rate
     * @param float       $perProduct           rate_per_product — multiplied by cart qty
     * @param float       $perKg                rate_per_kg — multiplied by billing weight
     * @param float       $percent              rate_percent — applied to subtotal (0-100, not 0.0-1.0)
     * @param float|null  $minRate              Method-level minimum clamp (null = no min)
     * @param float|null  $maxRate              Method-level maximum clamp (null = no max)
     * @param float       $weightConversionRate Per-rate weight conversion factor. cart_weight
     *                                          is DIVIDED by this before the per-kg term.
     *                                          Default 1.0 = no conversion. Zero or negative
     *                                          inputs are treated as 1.0 to avoid divide-by-zero.
     * @param float|null  $subtotalOverride     When non-null, use this value instead of $context->subtotal
     *                                          for the rate_percent term. Lets RateMatcher pass the
     *                                          per-method-resolved subtotal (Feature 3) without
     *                                          mutating the immutable CartContext.
     * @param float|null  $weightOverride       When non-null, use this value instead of $context->weight
     *                                          as the basis for the per-kg term. RateMatcher passes the
     *                                          per-method chargeable weight (Feature 7 volumetric).
     *                                          The weightConversionRate factor still applies on top.
     * @return float                            Final shipping cost, never negative
     */
    public function calculate(
        CartContext $context,
        float $base,
        float $perProduct,
        float $perKg,
        float $percent,
        ?float $minRate = null,
        ?float $maxRate = null,
        float $weightConversionRate = 1.0,
        ?float $subtotalOverride = null,
        ?float $weightOverride = null
    ): float {
        $factor = $weightConversionRate > 0.0 ? $weightConversionRate : 1.0;
        $weight = $weightOverride ?? $context->weight;
        $billingWeight = $weight / $factor;
        $subtotal = $subtotalOverride ?? $context->subtotal;

        $raw = $base
            + ($perProduct * $context->qty)
            + ($perKg * $billingWeight)
            + ($percent * $subtotal / 100.0);

        // Apply method-level clamps
        if ($minRate !== null && $raw < $minRate) {
            $raw = $minRate;
        }
        if ($maxRate !== null && $raw > $maxRate) {
            $raw = $maxRate;
        }

        // Final floor at zero — Magento can't quote negative shipping
        return max(0.0, $raw);
    }

    /**
     * Aggregate a set of rates according to the method's multi-shipping-type
     * mode. Used when the cart has multiple shipping types and several rates
     * matched as a result.
     *
     * @param float[] $costs Individual rate costs, one per matching rate
     * @param string  $mode  'sum' | 'min' | 'max'
     * @return float         Aggregated cost (0.0 if input empty)
     */
    public function aggregate(array $costs, string $mode): float
    {
        if (empty($costs)) {
            return 0.0;
        }

        return match ($mode) {
            'min' => min($costs),
            'max' => max($costs),
            default => array_sum($costs),  // 'sum' — also the safe default for unknown values
        };
    }
}
