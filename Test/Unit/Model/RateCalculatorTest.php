<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Test\Unit\Model;

use ETechFlow\ShippingTableRates\Model\CartContext;
use ETechFlow\ShippingTableRates\Model\RateCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pure-math tests for the rate formula and the multi-type aggregator.
 *
 * Formula:  base + (per_product × qty) + (per_kg × weight) + (percent% × subtotal)
 */
class RateCalculatorTest extends TestCase
{
    private RateCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new RateCalculator();
    }

    private function buildContext(int $qty, float $weight, float $subtotal): CartContext
    {
        return new CartContext(
            countryCode: 'GB',
            regionCode: '',
            city: '',
            postcode: '',
            weight: $weight,
            qty: $qty,
            subtotal: $subtotal,
            customerGroupId: 1,
            shippingTypes: []
        );
    }

    // -----------------------------------------------------------------
    // Each component in isolation
    // -----------------------------------------------------------------

    public function testFlatBaseOnly(): void
    {
        $ctx = $this->buildContext(qty: 5, weight: 10.0, subtotal: 100.0);
        $this->assertSame(7.50, $this->calc->calculate($ctx, 7.50, 0.0, 0.0, 0.0));
    }

    public function testPerProductOnly(): void
    {
        // £2 per item × 3 items = £6
        $ctx = $this->buildContext(qty: 3, weight: 1.0, subtotal: 50.0);
        $this->assertSame(6.0, $this->calc->calculate($ctx, 0.0, 2.0, 0.0, 0.0));
    }

    public function testPerKgOnly(): void
    {
        // £0.50/kg × 4.5 kg = £2.25
        $ctx = $this->buildContext(qty: 1, weight: 4.5, subtotal: 50.0);
        $this->assertSame(2.25, $this->calc->calculate($ctx, 0.0, 0.0, 0.50, 0.0));
    }

    public function testPercentOnly(): void
    {
        // 5% of £80 = £4
        $ctx = $this->buildContext(qty: 1, weight: 1.0, subtotal: 80.0);
        $this->assertSame(4.0, $this->calc->calculate($ctx, 0.0, 0.0, 0.0, 5.0));
    }

    // -----------------------------------------------------------------
    // Combined components
    // -----------------------------------------------------------------

    public function testAllFourComponentsCombined(): void
    {
        // base 3 + (1 × 2 items) + (0.5 × 4kg) + (10% × 50) = 3 + 2 + 2 + 5 = 12
        $ctx = $this->buildContext(qty: 2, weight: 4.0, subtotal: 50.0);
        $this->assertSame(12.0, $this->calc->calculate($ctx, 3.0, 1.0, 0.5, 10.0));
    }

    // -----------------------------------------------------------------
    // Clamps
    // -----------------------------------------------------------------

    public function testMinClampLiftsLowResult(): void
    {
        // Raw would be 1.0, min clamp = 5.0
        $ctx = $this->buildContext(qty: 1, weight: 0.0, subtotal: 0.0);
        $this->assertSame(5.0, $this->calc->calculate($ctx, 1.0, 0.0, 0.0, 0.0, 5.0));
    }

    public function testMaxClampLowersHighResult(): void
    {
        // Raw would be 50.0, max clamp = 20.0
        $ctx = $this->buildContext(qty: 10, weight: 0.0, subtotal: 0.0);
        $this->assertSame(20.0, $this->calc->calculate($ctx, 0.0, 5.0, 0.0, 0.0, null, 20.0));
    }

    public function testBothClampsActive(): void
    {
        $ctx = $this->buildContext(qty: 1, weight: 0.0, subtotal: 0.0);

        // Raw 0.5 → clamped up to min 1.0
        $this->assertSame(1.0, $this->calc->calculate($ctx, 0.5, 0.0, 0.0, 0.0, 1.0, 10.0));

        // Raw 15.0 → clamped down to max 10.0
        $this->assertSame(10.0, $this->calc->calculate($ctx, 15.0, 0.0, 0.0, 0.0, 1.0, 10.0));
    }

    public function testNoClampsByDefault(): void
    {
        // Raw value passes through untouched
        $ctx = $this->buildContext(qty: 1, weight: 0.0, subtotal: 0.0);
        $this->assertSame(99.99, $this->calc->calculate($ctx, 99.99, 0.0, 0.0, 0.0));
    }

    public function testNegativeRawClampedToZero(): void
    {
        // A merchant could enter a promotional negative base; the final cost
        // is floored at zero so Magento never sees negative shipping.
        $ctx = $this->buildContext(qty: 1, weight: 0.0, subtotal: 0.0);
        $this->assertSame(0.0, $this->calc->calculate($ctx, -5.0, 0.0, 0.0, 0.0));
    }

    public function testMinClampOfZeroDoesNotLiftZero(): void
    {
        $ctx = $this->buildContext(qty: 1, weight: 0.0, subtotal: 0.0);
        $this->assertSame(0.0, $this->calc->calculate($ctx, 0.0, 0.0, 0.0, 0.0, 0.0));
    }

    // -----------------------------------------------------------------
    // Weight unit conversion (v1.1.0+)
    // -----------------------------------------------------------------

    public function testWeightConversionDefaultsToOne(): void
    {
        // No explicit conversion factor: per-kg × weight = 1.0 × 10 = 10
        $ctx = $this->buildContext(qty: 1, weight: 10.0, subtotal: 0.0);
        $this->assertSame(10.0, $this->calc->calculate($ctx, 0.0, 0.0, 1.0, 0.0));
    }

    public function testWeightConversionLbsToKg(): void
    {
        // Cart weight 10 lbs, conversion 2.2046 → billing weight 4.5359 kg
        // Per-kg rate £2 → 4.5359 × 2 = 9.0718, rounded by float arithmetic
        $ctx = $this->buildContext(qty: 1, weight: 10.0, subtotal: 0.0);
        $cost = $this->calc->calculate($ctx, 0.0, 0.0, 2.0, 0.0, null, null, 2.2046);
        $this->assertEqualsWithDelta(9.0719, $cost, 0.001);
    }

    public function testWeightConversionKgToLbs(): void
    {
        // Cart weight 5 kg, conversion 0.4536 → billing 11.0229 lbs
        // Per-kg rate £1 → 11.0229
        $ctx = $this->buildContext(qty: 1, weight: 5.0, subtotal: 0.0);
        $cost = $this->calc->calculate($ctx, 0.0, 0.0, 1.0, 0.0, null, null, 0.4536);
        $this->assertEqualsWithDelta(11.0229, $cost, 0.001);
    }

    public function testWeightConversionZeroFactorTreatedAsOne(): void
    {
        // A divide-by-zero would crash the formula; safety net coerces 0 → 1
        $ctx = $this->buildContext(qty: 1, weight: 10.0, subtotal: 0.0);
        $this->assertSame(10.0, $this->calc->calculate($ctx, 0.0, 0.0, 1.0, 0.0, null, null, 0.0));
    }

    public function testWeightConversionNegativeFactorTreatedAsOne(): void
    {
        // Negative factor would flip the sign of the weight term; coerce to 1
        $ctx = $this->buildContext(qty: 1, weight: 10.0, subtotal: 0.0);
        $this->assertSame(10.0, $this->calc->calculate($ctx, 0.0, 0.0, 1.0, 0.0, null, null, -2.5));
    }

    public function testWeightConversionDoesNotAffectOtherComponents(): void
    {
        // Only the per-kg term should be affected by the conversion. Base,
        // per-product, and percent terms remain untouched.
        $ctx = $this->buildContext(qty: 2, weight: 10.0, subtotal: 100.0);
        // base 5 + (per_product 1 × 2) + (per_kg 1 × 10/2.2046) + (10% × 100)
        // = 5 + 2 + 4.5359 + 10 = 21.5359
        $cost = $this->calc->calculate($ctx, 5.0, 1.0, 1.0, 10.0, null, null, 2.2046);
        $this->assertEqualsWithDelta(21.5359, $cost, 0.001);
    }

    // -----------------------------------------------------------------
    // Feature 3: subtotalOverride threads per-method subtotal through
    // -----------------------------------------------------------------

    public function testSubtotalOverrideReplacesContextSubtotalInPercentTerm(): void
    {
        // Context says subtotal=100 → 10% would be £10. Override says
        // subtotal=50 → 10% should be £5. Only the percent term changes.
        $ctx = $this->buildContext(qty: 0, weight: 0.0, subtotal: 100.0);
        $cost = $this->calc->calculate($ctx, 0.0, 0.0, 0.0, 10.0, null, null, 1.0, 50.0);
        $this->assertSame(5.0, $cost);
    }

    public function testNullOverrideFallsBackToContextSubtotal(): void
    {
        // Explicit null = use context — back-compat for callers that don't
        // know about Feature 3.
        $ctx = $this->buildContext(qty: 0, weight: 0.0, subtotal: 100.0);
        $cost = $this->calc->calculate($ctx, 0.0, 0.0, 0.0, 10.0, null, null, 1.0, null);
        $this->assertSame(10.0, $cost);
    }

    public function testSubtotalOverrideDoesNotAffectOtherTerms(): void
    {
        // Base + per_product + per_kg are computed from context, not the
        // override. Only the percent term picks up the new subtotal.
        $ctx = $this->buildContext(qty: 2, weight: 4.0, subtotal: 200.0);
        // base 5 + (per_product 1.5 × 2) + (per_kg 0.5 × 4) + (10% × override 80)
        // = 5 + 3 + 2 + 8 = 18
        $cost = $this->calc->calculate($ctx, 5.0, 1.5, 0.5, 10.0, null, null, 1.0, 80.0);
        $this->assertSame(18.0, $cost);
    }

    // -----------------------------------------------------------------
    // Feature 7: weightOverride threads chargeable weight into per-kg
    // -----------------------------------------------------------------

    public function testWeightOverrideReplacesContextWeightInPerKgTerm(): void
    {
        // Context weight 2 kg → per_kg term = £4. Override 5 kg → £10.
        // Other terms (base/qty/percent) untouched.
        $ctx = $this->buildContext(qty: 0, weight: 2.0, subtotal: 0.0);
        $cost = $this->calc->calculate($ctx, 0.0, 0.0, 2.0, 0.0, null, null, 1.0, null, 5.0);
        $this->assertSame(10.0, $cost);
    }

    public function testNullWeightOverrideFallsBackToContextWeight(): void
    {
        $ctx = $this->buildContext(qty: 0, weight: 3.0, subtotal: 0.0);
        $cost = $this->calc->calculate($ctx, 0.0, 0.0, 2.0, 0.0, null, null, 1.0, null, null);
        $this->assertSame(6.0, $cost);
    }

    public function testWeightOverrideAndConversionRateCompose(): void
    {
        // Override weight 5 kg, conversion 2.2046 (lbs→kg) → billing weight
        // 5 / 2.2046 = 2.2676 kg. Per_kg 2 → 4.535 cost.
        $ctx = $this->buildContext(qty: 0, weight: 999.0, subtotal: 0.0);
        $cost = $this->calc->calculate($ctx, 0.0, 0.0, 2.0, 0.0, null, null, 2.2046, null, 5.0);
        $this->assertEqualsWithDelta(4.5359, $cost, 0.001);
    }

    public function testWeightOverrideDoesNotAffectOtherTerms(): void
    {
        // Override should affect ONLY the per-kg term.
        $ctx = $this->buildContext(qty: 4, weight: 1.0, subtotal: 200.0);
        // base 5 + (per_product 1 × 4) + (per_kg 1 × override 10) + (10% × 200)
        // = 5 + 4 + 10 + 20 = 39
        $cost = $this->calc->calculate($ctx, 5.0, 1.0, 1.0, 10.0, null, null, 1.0, null, 10.0);
        $this->assertSame(39.0, $cost);
    }

    // -----------------------------------------------------------------
    // Aggregate (multi-shipping-type)
    // -----------------------------------------------------------------

    public function testAggregateSumMode(): void
    {
        $this->assertSame(15.0, $this->calc->aggregate([5.0, 7.0, 3.0], 'sum'));
    }

    public function testAggregateMinMode(): void
    {
        $this->assertSame(3.0, $this->calc->aggregate([5.0, 7.0, 3.0], 'min'));
    }

    public function testAggregateMaxMode(): void
    {
        $this->assertSame(7.0, $this->calc->aggregate([5.0, 7.0, 3.0], 'max'));
    }

    public function testAggregateUnknownModeDefaultsToSum(): void
    {
        // Safety: an unknown mode shouldn't crash — treat as sum
        $this->assertSame(15.0, $this->calc->aggregate([5.0, 7.0, 3.0], 'wat'));
    }

    public function testAggregateEmptyReturnsZero(): void
    {
        $this->assertSame(0.0, $this->calc->aggregate([], 'sum'));
        $this->assertSame(0.0, $this->calc->aggregate([], 'min'));
        $this->assertSame(0.0, $this->calc->aggregate([], 'max'));
    }
}
