<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Test\Unit\Model;

use ETechFlow\ShippingTableRates\Model\CartContext;
use ETechFlow\ShippingTableRates\Model\Method;
use PHPUnit\Framework\TestCase;

/**
 * The CartContext value object is a thin holder, so the only logic worth
 * testing is hasShippingType() (case-insensitive lookup with safe handling
 * of whitespace and empty needles) plus subtotalForMethod() (Feature 3:
 * per-method subtotal variant selection).
 */
class CartContextTest extends TestCase
{
    private function buildContext(
        array $shippingTypes,
        float $subtotal = 10.0,
        ?float $subtotalAfterDiscount = null,
        ?float $subtotalInclTax = null,
        ?float $subtotalInclTaxAfterDiscount = null
    ): CartContext {
        return new CartContext(
            countryCode: 'GB',
            regionCode: 'ENG',
            city: 'London',
            postcode: 'SW1A 1AA',
            weight: 1.0,
            qty: 1,
            subtotal: $subtotal,
            customerGroupId: 1,
            shippingTypes: $shippingTypes,
            subtotalAfterDiscount: $subtotalAfterDiscount,
            subtotalInclTax: $subtotalInclTax,
            subtotalInclTaxAfterDiscount: $subtotalInclTaxAfterDiscount
        );
    }

    /**
     * Build a Method stub whose getData() reads from $data. The typed accessors
     * (getUsePriceAfterDiscount, getUsePriceIncludingTax) then return the
     * right values.
     */
    private function buildMethod(bool $afterDiscount, bool $inclTax): Method
    {
        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $method->method('getData')->willReturnCallback(static function ($key) use ($afterDiscount, $inclTax) {
            return match ($key) {
                'use_price_after_discount' => $afterDiscount,
                'use_price_including_tax'  => $inclTax,
                default                    => null,
            };
        });
        return $method;
    }

    public function testReturnsTrueForExactMatch(): void
    {
        $ctx = $this->buildContext(['fragile', 'standard']);
        $this->assertTrue($ctx->hasShippingType('fragile'));
        $this->assertTrue($ctx->hasShippingType('standard'));
    }

    public function testCaseInsensitive(): void
    {
        $ctx = $this->buildContext(['fragile']);
        $this->assertTrue($ctx->hasShippingType('FRAGILE'));
        $this->assertTrue($ctx->hasShippingType('Fragile'));
    }

    public function testIgnoresSurroundingWhitespace(): void
    {
        $ctx = $this->buildContext(['fragile']);
        $this->assertTrue($ctx->hasShippingType('  fragile  '));
    }

    public function testReturnsFalseForMissingType(): void
    {
        $ctx = $this->buildContext(['standard']);
        $this->assertFalse($ctx->hasShippingType('fragile'));
    }

    public function testReturnsFalseForEmptyNeedle(): void
    {
        $ctx = $this->buildContext(['fragile']);
        $this->assertFalse($ctx->hasShippingType(''));
        $this->assertFalse($ctx->hasShippingType('   '));
    }

    public function testReturnsFalseWhenContextIsEmpty(): void
    {
        $ctx = $this->buildContext([]);
        $this->assertFalse($ctx->hasShippingType('fragile'));
    }

    // ---------------------------------------------------------------------
    // subtotalForMethod — Feature 3 (per-method "use price after discount" /
    // "use price including tax" toggles)
    // ---------------------------------------------------------------------

    public function testSubtotalForMethodReturnsPreTaxPreDiscountByDefault(): void
    {
        // Both flags FALSE → use the canonical $subtotal field.
        $ctx = $this->buildContext(
            [],
            subtotal: 100.0,
            subtotalAfterDiscount: 90.0,
            subtotalInclTax: 120.0,
            subtotalInclTaxAfterDiscount: 108.0
        );
        $method = $this->buildMethod(afterDiscount: false, inclTax: false);
        $this->assertSame(100.0, $ctx->subtotalForMethod($method));
    }

    public function testSubtotalForMethodReturnsAfterDiscountWhenFlagSet(): void
    {
        $ctx = $this->buildContext(
            [],
            subtotal: 100.0,
            subtotalAfterDiscount: 90.0,
            subtotalInclTax: 120.0,
            subtotalInclTaxAfterDiscount: 108.0
        );
        $method = $this->buildMethod(afterDiscount: true, inclTax: false);
        $this->assertSame(90.0, $ctx->subtotalForMethod($method));
    }

    public function testSubtotalForMethodReturnsInclTaxWhenFlagSet(): void
    {
        $ctx = $this->buildContext(
            [],
            subtotal: 100.0,
            subtotalAfterDiscount: 90.0,
            subtotalInclTax: 120.0,
            subtotalInclTaxAfterDiscount: 108.0
        );
        $method = $this->buildMethod(afterDiscount: false, inclTax: true);
        $this->assertSame(120.0, $ctx->subtotalForMethod($method));
    }

    public function testSubtotalForMethodReturnsInclTaxAfterDiscountWhenBothFlagsSet(): void
    {
        $ctx = $this->buildContext(
            [],
            subtotal: 100.0,
            subtotalAfterDiscount: 90.0,
            subtotalInclTax: 120.0,
            subtotalInclTaxAfterDiscount: 108.0
        );
        $method = $this->buildMethod(afterDiscount: true, inclTax: true);
        $this->assertSame(108.0, $ctx->subtotalForMethod($method));
    }

    public function testSubtotalForMethodFallsBackToSubtotalWhenVariantIsNull(): void
    {
        // Older tests that construct CartContext without the variants — flags
        // resolve back to the canonical $subtotal so behaviour is unchanged.
        $ctx = $this->buildContext([], subtotal: 50.0);
        $this->assertSame(50.0, $ctx->subtotalForMethod($this->buildMethod(false, false)));
        $this->assertSame(50.0, $ctx->subtotalForMethod($this->buildMethod(true,  false)));
        $this->assertSame(50.0, $ctx->subtotalForMethod($this->buildMethod(false, true)));
        $this->assertSame(50.0, $ctx->subtotalForMethod($this->buildMethod(true,  true)));
    }

    // ---------------------------------------------------------------------
    // chargeableWeightForMethod — Feature 7 (volumetric / dimensional weight)
    // ---------------------------------------------------------------------

    /**
     * Stub a method whose getUseVolumetricWeight + getVolumetricDivisor
     * return the given values. Separate helper from buildMethod() because
     * the F3 helper only exposes the discount/tax flags.
     */
    private function buildVolumetricMethod(bool $useVolumetric, float $divisor = 5000.0): \ETechFlow\ShippingTableRates\Model\Method
    {
        $method = $this->getMockBuilder(\ETechFlow\ShippingTableRates\Model\Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUseVolumetricWeight', 'getVolumetricDivisor'])
            ->getMock();
        $method->method('getUseVolumetricWeight')->willReturn($useVolumetric);
        $method->method('getVolumetricDivisor')->willReturn($divisor);
        return $method;
    }

    private function buildContextWithVolumetric(float $weight, float $volumetricCm3): \ETechFlow\ShippingTableRates\Model\CartContext
    {
        return new \ETechFlow\ShippingTableRates\Model\CartContext(
            countryCode: 'GB',
            regionCode: '',
            city: '',
            postcode: '',
            weight: $weight,
            qty: 1,
            subtotal: 0.0,
            customerGroupId: 0,
            shippingTypes: [],
            volumetricCm3: $volumetricCm3,
        );
    }

    public function testChargeableWeightReturnsActualWhenFlagOff(): void
    {
        // Method doesn't opt in — actual weight regardless of cm³.
        $ctx = $this->buildContextWithVolumetric(weight: 2.0, volumetricCm3: 50000.0);
        $this->assertSame(2.0, $ctx->chargeableWeightForMethod($this->buildVolumetricMethod(false)));
    }

    public function testChargeableWeightUsesVolumetricWhenLarger(): void
    {
        // 30 × 30 × 30 = 27000 cm³ ÷ 5000 = 5.4 kg volumetric. Actual is 2 kg.
        // max(2, 5.4) = 5.4.
        $ctx = $this->buildContextWithVolumetric(weight: 2.0, volumetricCm3: 27000.0);
        $this->assertEqualsWithDelta(
            5.4,
            $ctx->chargeableWeightForMethod($this->buildVolumetricMethod(true, 5000.0)),
            0.001
        );
    }

    public function testChargeableWeightUsesActualWhenLarger(): void
    {
        // 10 × 10 × 10 = 1000 cm³ ÷ 5000 = 0.2 kg volumetric. Actual 3 kg.
        // max(3, 0.2) = 3.
        $ctx = $this->buildContextWithVolumetric(weight: 3.0, volumetricCm3: 1000.0);
        $this->assertSame(3.0, $ctx->chargeableWeightForMethod($this->buildVolumetricMethod(true, 5000.0)));
    }

    public function testChargeableWeightWithDifferentDivisor(): void
    {
        // FedEx ground uses 6000. Same cm³ → smaller volumetric weight.
        // 27000 ÷ 6000 = 4.5. Actual 2 → max = 4.5.
        $ctx = $this->buildContextWithVolumetric(weight: 2.0, volumetricCm3: 27000.0);
        $this->assertEqualsWithDelta(
            4.5,
            $ctx->chargeableWeightForMethod($this->buildVolumetricMethod(true, 6000.0)),
            0.001
        );
    }

    public function testChargeableWeightFallsBackWhenNoDimensions(): void
    {
        // Cart has no dimension data → volumetricCm3 = 0 → max(weight, 0)
        // = weight. Safe for stores that turn the flag on before all
        // products have dimensions filled in.
        $ctx = $this->buildContextWithVolumetric(weight: 4.0, volumetricCm3: 0.0);
        $this->assertSame(4.0, $ctx->chargeableWeightForMethod($this->buildVolumetricMethod(true)));
    }
}
