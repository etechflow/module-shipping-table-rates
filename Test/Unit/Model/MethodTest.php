<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Test\Unit\Model;

use ETechFlow\ShippingTableRates\Model\Method;
use PHPUnit\Framework\TestCase;

/**
 * The Method model is mostly thin typed accessors over getData()/setData();
 * the only logic worth pinning is the per-field normalisation done by
 * `getShipForFreeTypes()` (Feature 4 / Amasty parity) which parses a
 * comma-separated DB string into a clean list of lowercase shipping_type
 * identifiers.
 */
class MethodTest extends TestCase
{
    private function buildMethod(?string $rawShipForFreeTypes): Method
    {
        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $method->method('getData')->willReturnCallback(
            static fn($key) => $key === 'ship_for_free_types' ? $rawShipForFreeTypes : null
        );
        return $method;
    }

    public function testNullReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->buildMethod(null)->getShipForFreeTypes());
    }

    public function testEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->buildMethod('')->getShipForFreeTypes());
        $this->assertSame([], $this->buildMethod('   ')->getShipForFreeTypes());
    }

    public function testSingleValueParsedAndLowercased(): void
    {
        $this->assertSame(['fragile'], $this->buildMethod('Fragile')->getShipForFreeTypes());
    }

    public function testCommaSeparatedValuesAreSplit(): void
    {
        $this->assertSame(
            ['fragile', 'oversized'],
            $this->buildMethod('fragile,oversized')->getShipForFreeTypes()
        );
    }

    public function testWhitespaceAroundEntriesIsTrimmed(): void
    {
        $this->assertSame(
            ['fragile', 'oversized'],
            $this->buildMethod('  fragile  ,   oversized  ')->getShipForFreeTypes()
        );
    }

    public function testMixedCaseIsNormalisedToLower(): void
    {
        $this->assertSame(
            ['fragile', 'cold'],
            $this->buildMethod('Fragile, COLD')->getShipForFreeTypes()
        );
    }

    public function testDuplicatesAreDeduped(): void
    {
        // Same value entered twice (with different case + whitespace) should
        // collapse to a single entry. Order of first occurrence is preserved.
        $this->assertSame(
            ['fragile', 'oversized'],
            $this->buildMethod('fragile, FRAGILE, oversized,  fragile ')->getShipForFreeTypes()
        );
    }

    public function testEmptyCommaSeparatedEntriesAreDropped(): void
    {
        // Trailing comma / double commas shouldn't yield empty-string entries.
        $this->assertSame(
            ['fragile', 'oversized'],
            $this->buildMethod('fragile,,oversized,')->getShipForFreeTypes()
        );
    }

    // ---------------------------------------------------------------------
    // Feature 6 — store_view_ids / customer_group_ids parsing (parseIdCsv)
    // ---------------------------------------------------------------------

    private function buildMethodWith(array $data): Method
    {
        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $method->method('getData')->willReturnCallback(static fn($k) => $data[$k] ?? null);
        return $method;
    }

    public function testStoreViewIdsNullReturnsNull(): void
    {
        // NULL = "applies to all store views" — RateMatcher relies on this
        // to skip the scope filter entirely.
        $this->assertNull($this->buildMethodWith([])->getStoreViewIds());
    }

    public function testStoreViewIdsEmptyStringReturnsNull(): void
    {
        $this->assertNull($this->buildMethodWith(['store_view_ids' => ''])->getStoreViewIds());
        $this->assertNull($this->buildMethodWith(['store_view_ids' => '  '])->getStoreViewIds());
    }

    public function testStoreViewIdsParsesIntegerList(): void
    {
        $this->assertSame(
            [1, 2, 5],
            $this->buildMethodWith(['store_view_ids' => '1,2,5'])->getStoreViewIds()
        );
    }

    public function testStoreViewIdsTrimsWhitespaceAndDropsNonNumeric(): void
    {
        // 'abc' is dropped (non-digit), '-3' is dropped (negative); ints
        // survive with whitespace stripped.
        $this->assertSame(
            [1, 4],
            $this->buildMethodWith(['store_view_ids' => ' 1 , abc, -3 ,4'])->getStoreViewIds()
        );
    }

    public function testStoreViewIdsDedupes(): void
    {
        $this->assertSame(
            [2, 3],
            $this->buildMethodWith(['store_view_ids' => '2,3,2,3'])->getStoreViewIds()
        );
    }

    public function testCustomerGroupIdsUsesSameParser(): void
    {
        // Both getters share parseIdCsv() — one test that the wiring works
        // for the customer-group column too is enough.
        $method = $this->buildMethodWith(['customer_group_ids' => '0,1,3']);
        $this->assertSame([0, 1, 3], $method->getCustomerGroupIds());
    }

    public function testCustomerGroupIdsAllowsZero(): void
    {
        // Group 0 = NOT LOGGED IN — must be a valid ID, distinct from "null".
        $this->assertSame(
            [0],
            $this->buildMethodWith(['customer_group_ids' => '0'])->getCustomerGroupIds()
        );
    }

    // ---------------------------------------------------------------------
    // Feature 7 — use_volumetric_weight + volumetric_divisor
    // ---------------------------------------------------------------------

    public function testUseVolumetricWeightDefaultsFalse(): void
    {
        $this->assertFalse($this->buildMethodWith([])->getUseVolumetricWeight());
    }

    public function testUseVolumetricWeightCastsTruthy(): void
    {
        $this->assertTrue($this->buildMethodWith(['use_volumetric_weight' => 1])->getUseVolumetricWeight());
        $this->assertTrue($this->buildMethodWith(['use_volumetric_weight' => '1'])->getUseVolumetricWeight());
        $this->assertFalse($this->buildMethodWith(['use_volumetric_weight' => 0])->getUseVolumetricWeight());
        $this->assertFalse($this->buildMethodWith(['use_volumetric_weight' => '0'])->getUseVolumetricWeight());
    }

    public function testVolumetricDivisorDefaultsTo5000(): void
    {
        // NULL column → 5000 (DHL / FedEx Air default — most common).
        $this->assertSame(5000.0, $this->buildMethodWith([])->getVolumetricDivisor());
    }

    public function testVolumetricDivisorReturnsConfiguredValue(): void
    {
        $this->assertSame(6000.0, $this->buildMethodWith(['volumetric_divisor' => '6000'])->getVolumetricDivisor());
        $this->assertSame(5000.0, $this->buildMethodWith(['volumetric_divisor' => 5000])->getVolumetricDivisor());
    }

    public function testVolumetricDivisorZeroOrNegativeCoercedToDefault(): void
    {
        // 0 would divide-by-zero the formula; negative would flip the sign.
        // Defensive coercion to the 5000 default keeps checkout safe.
        $this->assertSame(5000.0, $this->buildMethodWith(['volumetric_divisor' => 0])->getVolumetricDivisor());
        $this->assertSame(5000.0, $this->buildMethodWith(['volumetric_divisor' => -100])->getVolumetricDivisor());
    }

    public function testVolumetricDivisorEmptyStringTreatedAsNull(): void
    {
        // Admin form submits "" when the merchant clears the field — should
        // resolve to the default, not to 0.0.
        $this->assertSame(5000.0, $this->buildMethodWith(['volumetric_divisor' => ''])->getVolumetricDivisor());
    }
}
