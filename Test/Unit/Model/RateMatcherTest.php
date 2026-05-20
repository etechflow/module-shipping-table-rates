<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Test\Unit\Model;

use ETechFlow\ShippingTableRates\Model\CartContext;
use ETechFlow\ShippingTableRates\Model\MatchResult;
use ETechFlow\ShippingTableRates\Model\Method;
use ETechFlow\ShippingTableRates\Model\Rate;
use ETechFlow\ShippingTableRates\Model\RateCalculator;
use ETechFlow\ShippingTableRates\Model\RateMatcher;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\Collection as RateCollection;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\CollectionFactory as RateCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the RateMatcher's lookup algorithm against the full condition
 * surface. Uses the real RateCalculator (it's pure math) and mocks the
 * collection factory to return seeded Rate models — the matcher then runs
 * its filter + group + aggregate pipeline against in-memory rows.
 *
 * Rate / Method mocks override getData() so the real typed accessors keep
 * working — single source of truth for each row's column values.
 */
class RateMatcherTest extends TestCase
{
    private RateCollectionFactory|MockObject $rateCollectionFactory;
    private LoggerInterface|MockObject $logger;
    private RateMatcher $matcher;

    protected function setUp(): void
    {
        $this->rateCollectionFactory = $this->createMock(RateCollectionFactory::class);
        $this->logger                = $this->createMock(LoggerInterface::class);
        $this->matcher               = new RateMatcher(
            $this->rateCollectionFactory,
            new RateCalculator(),
            $this->logger
        );
    }

    /**
     * Build a Rate stub whose getData() reads from $data. Real typed accessors
     * then return the right values via their internal getData() calls.
     */
    private function buildRate(array $data): Rate
    {
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $rate->method('getData')->willReturnCallback(
            static fn($key) => $data[$key] ?? null
        );
        return $rate;
    }

    /**
     * Build a Method stub returning the given values from its accessors.
     * method_id default 1; multi_type_mode default 'sum'; clamps null.
     */
    private function buildMethod(array $data = []): Method
    {
        $defaults = [
            'method_id'        => 1,
            'multi_type_mode'  => 'sum',
            'min_rate'         => null,
            'max_rate'         => null,
        ];
        $merged = array_merge($defaults, $data);

        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $method->method('getData')->willReturnCallback(
            static fn($key) => $merged[$key] ?? null
        );
        return $method;
    }

    /**
     * Stub the collection factory to yield $rates from foreach($collection).
     * The chained filter/sort calls are no-op fluent stubs.
     */
    private function stubCollection(array $rates): void
    {
        $collection = $this->createMock(RateCollection::class);
        $collection->method('addMethodFilter')->willReturnSelf();
        $collection->method('addCountryFilter')->willReturnSelf();
        $collection->method('addSortOrderAsc')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rates));

        $this->rateCollectionFactory->method('create')->willReturn($collection);
    }

    private function buildContext(array $overrides = []): CartContext
    {
        $defaults = [
            'countryCode'     => 'GB',
            'regionCode'      => 'ENG',
            'city'            => 'London',
            'postcode'        => 'SW1A 1AA',
            'weight'          => 5.0,
            'qty'             => 3,
            'subtotal'        => 100.0,
            'customerGroupId' => 1,
            'shippingTypes'   => [],
        ];
        $merged = array_merge($defaults, $overrides);
        return new CartContext(...$merged);
    }

    // -----------------------------------------------------------------
    // Happy path: a single all-wildcard rate matches
    // -----------------------------------------------------------------

    public function testSingleWildcardRateMatches(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'          => 1,
                'method_id'        => 1,
                'rate_base'        => 5.0,
                'sort_order'       => 0,
                'is_active'        => 1,
            ]),
        ]);

        $result = $this->matcher->match($this->buildMethod(), $this->buildContext());

        $this->assertInstanceOf(MatchResult::class, $result);
        $this->assertSame(5.0, $result->totalCost);
        $this->assertCount(1, $result->winningRates);
    }

    public function testNoRatesReturnsNull(): void
    {
        $this->stubCollection([]);
        $this->assertNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    // -----------------------------------------------------------------
    // Geographic conditions
    // -----------------------------------------------------------------

    public function testCountryMismatchRejectsRate(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'      => 1,
                'method_id'    => 1,
                'country_code' => 'US',  // cart is GB
                'rate_base'    => 5.0,
            ]),
        ]);
        $this->assertNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    public function testRegionMismatchRejectsRate(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'     => 1,
                'method_id'   => 1,
                'region_code' => 'SCT',  // cart is ENG
                'rate_base'   => 5.0,
            ]),
        ]);
        $this->assertNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    public function testRegionMatchIsCaseInsensitive(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'     => 1,
                'method_id'   => 1,
                'region_code' => 'eng',  // mixed case
                'rate_base'   => 5.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(),
            $this->buildContext(['regionCode' => 'ENG'])
        );
        $this->assertNotNull($result);
    }

    public function testPostcodeRangeMatchesUkAlphanumeric(): void
    {
        // SW1A 1AA falls in SW1A 1AA - SW1A 1ZZ
        $this->stubCollection([
            $this->buildRate([
                'rate_id'   => 1,
                'method_id' => 1,
                'zip_from'  => 'SW1A 1AA',
                'zip_to'    => 'SW1A 1ZZ',
                'rate_base' => 5.0,
            ]),
        ]);
        $result = $this->matcher->match($this->buildMethod(), $this->buildContext());
        $this->assertNotNull($result);
    }

    public function testPostcodeRangeRejectsBelowFrom(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'   => 1,
                'method_id' => 1,
                'zip_from'  => 'SW1A 9AA',  // higher than cart's SW1A 1AA
                'zip_to'    => 'SW1Z 9ZZ',
                'rate_base' => 5.0,
            ]),
        ]);
        $this->assertNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    public function testPostcodeOnlyFromIsLowerBound(): void
    {
        // Single-postcode match (from = to)
        $this->stubCollection([
            $this->buildRate([
                'rate_id'   => 1,
                'method_id' => 1,
                'zip_from'  => 'SW1A 1AA',
                'zip_to'    => 'SW1A 1AA',
                'rate_base' => 5.0,
            ]),
        ]);
        $result = $this->matcher->match($this->buildMethod(), $this->buildContext());
        $this->assertNotNull($result);
    }

    // -----------------------------------------------------------------
    // Numeric range conditions
    // -----------------------------------------------------------------

    public function testWeightBelowRangeRejects(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'     => 1,
                'method_id'   => 1,
                'weight_from' => 10.0,
                'weight_to'   => 20.0,
                'rate_base'   => 5.0,
            ]),
        ]);
        // Cart weight 5.0, range 10-20 → reject
        $this->assertNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    public function testQtyAboveRangeRejects(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'   => 1,
                'method_id' => 1,
                'qty_from'  => 1,
                'qty_to'    => 2,
                'rate_base' => 5.0,
            ]),
        ]);
        // Cart qty 3, range 1-2 → reject
        $this->assertNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    public function testSubtotalInRangeMatches(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'       => 1,
                'method_id'     => 1,
                'subtotal_from' => 50.0,
                'subtotal_to'   => 150.0,
                'rate_base'     => 5.0,
            ]),
        ]);
        // Cart subtotal 100, range 50-150 → match
        $this->assertNotNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    public function testOpenEndedRangeMatchesAboveFrom(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'       => 1,
                'method_id'     => 1,
                'subtotal_from' => 50.0,
                // no subtotal_to → no upper bound
                'rate_base'     => 5.0,
            ]),
        ]);
        $this->assertNotNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    // -----------------------------------------------------------------
    // Customer group condition
    // -----------------------------------------------------------------

    public function testCustomerGroupMatch(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'           => 1,
                'method_id'         => 1,
                'customer_group_id' => '1,3,5',
                'rate_base'         => 5.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(),
            $this->buildContext(['customerGroupId' => 3])
        );
        $this->assertNotNull($result);
    }

    public function testCustomerGroupMismatch(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'           => 1,
                'method_id'         => 1,
                'customer_group_id' => '1,3,5',
                'rate_base'         => 5.0,
            ]),
        ]);
        $this->assertNull($this->matcher->match(
            $this->buildMethod(),
            $this->buildContext(['customerGroupId' => 99])
        ));
    }

    public function testCustomerGroupNullMatchesAllGroups(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'   => 1,
                'method_id' => 1,
                'rate_base' => 5.0,
            ]),
        ]);
        // Any group should match
        $this->assertNotNull($this->matcher->match(
            $this->buildMethod(),
            $this->buildContext(['customerGroupId' => 42])
        ));
    }

    // -----------------------------------------------------------------
    // Shipping-type grouping + multi-type aggregation
    // -----------------------------------------------------------------

    public function testShippingTypeSpecificRateOnlyAppliesWhenTypePresent(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'       => 1,
                'method_id'     => 1,
                'shipping_type' => 'fragile',
                'rate_base'     => 10.0,
            ]),
        ]);
        // Cart has NO fragile items → method doesn't apply
        $this->assertNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    public function testShippingTypeSpecificRateMatchesWhenTypePresent(): void
    {
        $this->stubCollection([
            $this->buildRate([
                'rate_id'       => 1,
                'method_id'     => 1,
                'shipping_type' => 'fragile',
                'rate_base'     => 10.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(),
            $this->buildContext(['shippingTypes' => ['fragile']])
        );
        $this->assertNotNull($result);
        $this->assertSame(10.0, $result->totalCost);
    }

    public function testMultiTypeSumMode(): void
    {
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'fragile',   'rate_base' => 3.0]),
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'shipping_type' => 'oversized', 'rate_base' => 7.0]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['multi_type_mode' => 'sum']),
            $this->buildContext(['shippingTypes' => ['fragile', 'oversized']])
        );
        $this->assertSame(10.0, $result->totalCost);
    }

    public function testMultiTypeMinMode(): void
    {
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'fragile',   'rate_base' => 3.0]),
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'shipping_type' => 'oversized', 'rate_base' => 7.0]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['multi_type_mode' => 'min']),
            $this->buildContext(['shippingTypes' => ['fragile', 'oversized']])
        );
        $this->assertSame(3.0, $result->totalCost);
    }

    public function testMultiTypeMaxMode(): void
    {
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'fragile',   'rate_base' => 3.0]),
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'shipping_type' => 'oversized', 'rate_base' => 7.0]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['multi_type_mode' => 'max']),
            $this->buildContext(['shippingTypes' => ['fragile', 'oversized']])
        );
        $this->assertSame(7.0, $result->totalCost);
    }

    public function testWildcardAndTypeSpecificRatesAggregate(): void
    {
        // Wildcard rate adds £5 baseline; fragile rate adds £4 on top
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'rate_base' => 5.0]),
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'shipping_type' => 'fragile', 'rate_base' => 4.0]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['multi_type_mode' => 'sum']),
            $this->buildContext(['shippingTypes' => ['fragile']])
        );
        $this->assertSame(9.0, $result->totalCost);
    }

    // -----------------------------------------------------------------
    // Tie-breaking — lower sort_order wins
    // -----------------------------------------------------------------

    public function testSortOrderPicksLowerNumberFirst(): void
    {
        // Both wildcard rates would match; sort_order=1 wins over sort_order=5
        // The collection mock is responsible for returning them in sorted order
        // (we configured it via addSortOrderAsc in real code).
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'sort_order' => 1, 'rate_base' => 7.0]),
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'sort_order' => 5, 'rate_base' => 99.0]),
        ]);
        $result = $this->matcher->match($this->buildMethod(), $this->buildContext());
        $this->assertSame(7.0, $result->totalCost);
    }

    // -----------------------------------------------------------------
    // Method-level clamps
    // -----------------------------------------------------------------

    public function testMethodMinClampLiftsLowCost(): void
    {
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'rate_base' => 1.0]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['min_rate' => 5.0]),
            $this->buildContext()
        );
        $this->assertSame(5.0, $result->totalCost);
    }

    public function testMethodMaxClampLowersHighCost(): void
    {
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'rate_base' => 100.0]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['max_rate' => 50.0]),
            $this->buildContext()
        );
        $this->assertSame(50.0, $result->totalCost);
    }

    // -----------------------------------------------------------------
    // Exception safety
    // -----------------------------------------------------------------

    public function testExceptionInPipelineReturnsNullAndLogs(): void
    {
        // Force the factory to throw — matcher should swallow + log + return null
        $this->rateCollectionFactory->method('create')
            ->willThrowException(new \RuntimeException('DB exploded'));

        $this->logger->expects($this->once())->method('error');

        $this->assertNull($this->matcher->match($this->buildMethod(), $this->buildContext()));
    }

    public function testNullMethodIdReturnsNullEarly(): void
    {
        $method = $this->buildMethod(['method_id' => null]);
        $this->assertNull($this->matcher->match($method, $this->buildContext()));
    }

    // -----------------------------------------------------------------
    // MatchResult helpers
    // -----------------------------------------------------------------

    public function testLongestDeliveryDaysAcrossWinningRates(): void
    {
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'fragile',   'rate_base' => 3.0, 'delivery_days' => 2]),
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'shipping_type' => 'oversized', 'rate_base' => 7.0, 'delivery_days' => 5]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['multi_type_mode' => 'sum']),
            $this->buildContext(['shippingTypes' => ['fragile', 'oversized']])
        );
        $this->assertSame(5, $result->getLongestDeliveryDays(), 'Should take the longest ETA when shipping mixed types');
    }

    public function testCombinedCommentSkipsEmpty(): void
    {
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'fragile',   'rate_base' => 3.0, 'comment' => 'Handle with care.']),
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'shipping_type' => 'oversized', 'rate_base' => 7.0, 'comment' => '']),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['multi_type_mode' => 'sum']),
            $this->buildContext(['shippingTypes' => ['fragile', 'oversized']])
        );
        $this->assertSame('Handle with care.', $result->getCombinedComment());
    }

    // -----------------------------------------------------------------
    // Feature 3: per-method "use price after discount" / "include tax"
    // -----------------------------------------------------------------

    /**
     * Build a context carrying all four subtotal variants so the matcher
     * can pick per-method. Canonical $subtotal stays at 100.
     */
    private function buildContextWithSubtotalVariants(): CartContext
    {
        return $this->buildContext([
            'subtotal'                     => 100.0,
            'subtotalAfterDiscount'        => 80.0,
            'subtotalInclTax'              => 120.0,
            'subtotalInclTaxAfterDiscount' => 96.0,
        ]);
    }

    public function testSubtotalFilterUsesPostDiscountWhenMethodOptsIn(): void
    {
        // Rate accepts 75-90 only. Cart pre-discount=100 (would NOT match),
        // post-discount=80 (would match). The method's flag should make the
        // matcher use post-discount → match.
        $this->stubCollection([
            $this->buildRate([
                'rate_id'       => 1,
                'method_id'     => 1,
                'subtotal_from' => 75.0,
                'subtotal_to'   => 90.0,
                'rate_base'     => 5.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['use_price_after_discount' => 1]),
            $this->buildContextWithSubtotalVariants()
        );
        $this->assertNotNull($result);
        $this->assertSame(5.0, $result->totalCost);
    }

    public function testSubtotalFilterRejectsPostDiscountValueWhenFlagOff(): void
    {
        // Same setup but the method DOESN'T opt in → matcher uses pre-discount
        // 100, which is outside the rate's 75-90 window → no match.
        $this->stubCollection([
            $this->buildRate([
                'rate_id'       => 1,
                'method_id'     => 1,
                'subtotal_from' => 75.0,
                'subtotal_to'   => 90.0,
                'rate_base'     => 5.0,
            ]),
        ]);
        $this->assertNull($this->matcher->match(
            $this->buildMethod(),
            $this->buildContextWithSubtotalVariants()
        ));
    }

    public function testRatePercentTermUsesPerMethodSubtotal(): void
    {
        // Rate charges 10% of subtotal. Cart canonical subtotal=100 (=£10
        // shipping), but the method opts INTO incl-tax+after-discount mode
        // → subtotal=96 → 10% = £9.60. Confirms the per-method subtotal
        // threads through to RateCalculator, not just the filter.
        $this->stubCollection([
            $this->buildRate([
                'rate_id'      => 1,
                'method_id'    => 1,
                'rate_base'    => 0.0,
                'rate_percent' => 10.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod([
                'use_price_after_discount' => 1,
                'use_price_including_tax'  => 1,
            ]),
            $this->buildContextWithSubtotalVariants()
        );
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(9.6, $result->totalCost, 0.001);
    }

    public function testRatePercentTermUsesCanonicalSubtotalWhenBothFlagsOff(): void
    {
        // Same rate, default method → 10% × 100 = £10.
        $this->stubCollection([
            $this->buildRate([
                'rate_id'      => 1,
                'method_id'    => 1,
                'rate_base'    => 0.0,
                'rate_percent' => 10.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(),
            $this->buildContextWithSubtotalVariants()
        );
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(10.0, $result->totalCost, 0.001);
    }

    // -----------------------------------------------------------------
    // Feature 4: ship_for_free_types per-type cost zeroing
    // -----------------------------------------------------------------

    public function testFreeShippingTypeContributesZero(): void
    {
        // Method declares "fragile" as ship-for-free. Cart has a fragile
        // item. The matching fragile rate (which would normally cost £5)
        // contributes 0 instead → total = 0.
        $this->stubCollection([
            $this->buildRate([
                'rate_id'       => 1,
                'method_id'     => 1,
                'shipping_type' => 'fragile',
                'rate_base'     => 5.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['ship_for_free_types' => 'fragile']),
            $this->buildContext(['shippingTypes' => ['fragile']])
        );
        $this->assertNotNull($result);
        $this->assertSame(0.0, $result->totalCost);
        // Winner is still recorded — MatchResult should reflect what would
        // have matched even though the cost contribution was zeroed.
        $this->assertCount(1, $result->winningRates);
    }

    public function testMixedCartZeroesOnlyFreeTypeGroups(): void
    {
        // Cart has fragile + oversized. Method's free list = [fragile].
        // Expected: fragile contributes 0; oversized contributes its rate
        // (£7); 'sum' aggregation → £7 total.
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'fragile',   'rate_base' => 5.0]),
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'shipping_type' => 'oversized', 'rate_base' => 7.0]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod([
                'ship_for_free_types' => 'fragile',
                'multi_type_mode'     => 'sum',
            ]),
            $this->buildContext(['shippingTypes' => ['fragile', 'oversized']])
        );
        $this->assertNotNull($result);
        $this->assertSame(7.0, $result->totalCost);
    }

    public function testWildcardRateNotZeroedEvenWhenCartIsAllFreeTypes(): void
    {
        // A wildcard rate (NULL shipping_type) is conceptually the
        // cart-level fallback — Feature 4's per-type override list
        // should NEVER apply to it. With fragile listed as free but the
        // matching rate being a wildcard, the wildcard rate's cost is
        // preserved.
        $this->stubCollection([
            $this->buildRate([
                'rate_id'       => 1,
                'method_id'     => 1,
                // no shipping_type → wildcard bucket
                'rate_base'     => 4.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['ship_for_free_types' => 'fragile']),
            $this->buildContext(['shippingTypes' => ['fragile']])
        );
        $this->assertNotNull($result);
        $this->assertSame(4.0, $result->totalCost);
    }

    public function testFreeShippingListNormalisedCaseInsensitive(): void
    {
        // Method's column stores "Fragile, OVERSIZED" with mixed case +
        // whitespace. Method::getShipForFreeTypes normalises to lowercase,
        // and the cart's shipping_type values (already lowercased by
        // CartContextBuilder) match correctly.
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'fragile',   'rate_base' => 5.0]),
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'shipping_type' => 'oversized', 'rate_base' => 7.0]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['ship_for_free_types' => '  Fragile, OVERSIZED  ', 'multi_type_mode' => 'sum']),
            $this->buildContext(['shippingTypes' => ['fragile', 'oversized']])
        );
        $this->assertNotNull($result);
        $this->assertSame(0.0, $result->totalCost);
    }

    public function testMethodMinRateLiftsZeroedFreeShippingResult(): void
    {
        // ship-for-free zeroes the per-group cost — but the method-level
        // min_rate clamp is applied AFTER aggregation. So a method with
        // ship_for_free_types=[fragile] AND min_rate=2.50 should quote
        // £2.50 (not £0) when the entire cart is fragile.
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'fragile', 'rate_base' => 5.0]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod([
                'ship_for_free_types' => 'fragile',
                'min_rate'            => 2.50,
            ]),
            $this->buildContext(['shippingTypes' => ['fragile']])
        );
        $this->assertNotNull($result);
        $this->assertSame(2.50, $result->totalCost);
    }

    // -----------------------------------------------------------------
    // Feature 6: method-level store-view + customer-group scope filter
    // -----------------------------------------------------------------

    public function testMethodWithNullScopeAppliesToEveryStore(): void
    {
        // Method has no scope columns (default — applies to all). Any
        // store / group on the context should match.
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'rate_base' => 5.0]),
        ]);
        $this->assertNotNull($this->matcher->match(
            $this->buildMethod(),
            $this->buildContext(['storeId' => 1, 'customerGroupId' => 1])
        ));
        // And same method matches a different store/group.
        $this->assertNotNull($this->matcher->match(
            $this->buildMethod(),
            $this->buildContext(['storeId' => 99, 'customerGroupId' => 5])
        ));
    }

    public function testMethodSkippedWhenStoreIdNotInScope(): void
    {
        // Method scoped to stores 2,3 — context's store 1 should be skipped
        // BEFORE the rate collection is even consulted (early-return null).
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'rate_base' => 5.0]),
        ]);
        $this->assertNull($this->matcher->match(
            $this->buildMethod(['store_view_ids' => '2,3']),
            $this->buildContext(['storeId' => 1])
        ));
    }

    public function testMethodMatchesWhenStoreIdInScope(): void
    {
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'rate_base' => 5.0]),
        ]);
        $this->assertNotNull($this->matcher->match(
            $this->buildMethod(['store_view_ids' => '2,3']),
            $this->buildContext(['storeId' => 2])
        ));
    }

    public function testMethodSkippedWhenCustomerGroupNotInScope(): void
    {
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'rate_base' => 5.0]),
        ]);
        $this->assertNull($this->matcher->match(
            $this->buildMethod(['customer_group_ids' => '0,1']),
            $this->buildContext(['customerGroupId' => 3])  // wholesale not in scope
        ));
    }

    public function testMethodScopeMatchesGroupZeroNotLoggedIn(): void
    {
        // Edge case: customer_group_id=0 (NOT LOGGED IN) must be a valid
        // match against scope '0' — distinct from null/empty.
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'rate_base' => 5.0]),
        ]);
        $this->assertNotNull($this->matcher->match(
            $this->buildMethod(['customer_group_ids' => '0,1']),
            $this->buildContext(['customerGroupId' => 0])
        ));
    }

    public function testBothScopesMustMatchSimultaneously(): void
    {
        // Method scoped to store 1 + group 1. A context with store 1 / group 2
        // must NOT match (one passes, the other fails → method skipped).
        $this->stubCollection([
            $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'rate_base' => 5.0]),
        ]);
        $this->assertNull($this->matcher->match(
            $this->buildMethod(['store_view_ids' => '1', 'customer_group_ids' => '1']),
            $this->buildContext(['storeId' => 1, 'customerGroupId' => 2])
        ));
        // Both match → match.
        $this->assertNotNull($this->matcher->match(
            $this->buildMethod(['store_view_ids' => '1', 'customer_group_ids' => '1']),
            $this->buildContext(['storeId' => 1, 'customerGroupId' => 1])
        ));
    }

    // -----------------------------------------------------------------
    // Feature 7: volumetric / dimensional weight
    // -----------------------------------------------------------------

    public function testWeightFilterUsesActualWhenVolumetricFlagOff(): void
    {
        // Method has volumetric off. Cart has light weight but big volume.
        // The 5-10 kg rate range should NOT match (actual weight is 2).
        $this->stubCollection([
            $this->buildRate([
                'rate_id'     => 1,
                'method_id'   => 1,
                'weight_from' => 5.0,
                'weight_to'   => 10.0,
                'rate_base'   => 5.0,
            ]),
        ]);
        $this->assertNull($this->matcher->match(
            $this->buildMethod(),
            $this->buildContext(['weight' => 2.0, 'volumetricCm3' => 50000.0])
        ));
    }

    public function testWeightFilterUsesVolumetricWhenLargerAndMethodOptsIn(): void
    {
        // Method opts in. 50000 cm³ / 5000 = 10 kg volumetric > 2 actual.
        // Range 5-10 NOW matches (chargeable = 10).
        $this->stubCollection([
            $this->buildRate([
                'rate_id'     => 1,
                'method_id'   => 1,
                'weight_from' => 5.0,
                'weight_to'   => 10.0,
                'rate_base'   => 5.0,
            ]),
        ]);
        $this->assertNotNull($this->matcher->match(
            $this->buildMethod(['use_volumetric_weight' => 1]),
            $this->buildContext(['weight' => 2.0, 'volumetricCm3' => 50000.0])
        ));
    }

    public function testRatePerKgTermUsesVolumetricWeight(): void
    {
        // Cart: 2 kg actual, 30000 cm³. Method: volumetric on, divisor 5000
        // → volumetric weight = 6 kg → chargeable = max(2, 6) = 6 kg.
        // Rate: rate_per_kg = 2 → cost = 6 × 2 = £12.
        $this->stubCollection([
            $this->buildRate([
                'rate_id'      => 1,
                'method_id'    => 1,
                'rate_base'    => 0.0,
                'rate_per_kg'  => 2.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['use_volumetric_weight' => 1]),
            $this->buildContext(['weight' => 2.0, 'volumetricCm3' => 30000.0])
        );
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(12.0, $result->totalCost, 0.001);
    }

    public function testCustomDivisorAppliesToPerKgTerm(): void
    {
        // FedEx ground divisor 6000. 30000 cm³ / 6000 = 5 kg volumetric.
        // Actual 2 → chargeable 5. Per_kg 2 → £10.
        $this->stubCollection([
            $this->buildRate([
                'rate_id'      => 1,
                'method_id'    => 1,
                'rate_base'    => 0.0,
                'rate_per_kg'  => 2.0,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod([
                'use_volumetric_weight' => 1,
                'volumetric_divisor'    => 6000.0,
            ]),
            $this->buildContext(['weight' => 2.0, 'volumetricCm3' => 30000.0])
        );
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(10.0, $result->totalCost, 0.001);
    }

    public function testVolumetricSafeWhenNoDimensionData(): void
    {
        // Cart with no dimensions (volumetricCm3=0). Even with the flag on,
        // chargeable = max(actual, 0) = actual. Match against actual weight.
        $this->stubCollection([
            $this->buildRate([
                'rate_id'     => 1,
                'method_id'   => 1,
                'weight_from' => 0.0,
                'weight_to'   => 5.0,
                'rate_base'   => 4.99,
            ]),
        ]);
        $result = $this->matcher->match(
            $this->buildMethod(['use_volumetric_weight' => 1]),
            $this->buildContext(['weight' => 3.0, 'volumetricCm3' => 0.0])
        );
        $this->assertNotNull($result);
        $this->assertSame(4.99, $result->totalCost);
    }
}
