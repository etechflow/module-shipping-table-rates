<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Test\Unit\Model\Conflict;

use ETechFlow\ShippingTableRates\Model\Conflict\ConflictDetector;
use ETechFlow\ShippingTableRates\Model\Rate;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\Collection as RateCollection;
use ETechFlow\ShippingTableRates\Model\ResourceModel\Rate\CollectionFactory as RateCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the v1.0 differentiator conflict detector.
 *
 * Two rules overlap when EVERY condition dimension's ranges intersect
 * (with NULL = wildcard). Reports both the overlap AND whether the rules
 * share sort_order (= non-deterministic winner = real bug).
 */
class ConflictDetectorTest extends TestCase
{
    private RateCollectionFactory|MockObject $factory;
    private ConflictDetector $detector;

    protected function setUp(): void
    {
        $this->factory = $this->createMock(RateCollectionFactory::class);
        $this->detector = new ConflictDetector($this->factory);
    }

    /**
     * Build a Rate stub whose getData() reads from $data.
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
     * Stub the collection factory so the detector's query returns these rates.
     */
    private function stubExistingRates(array $rates): void
    {
        $collection = $this->createMock(RateCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rates));
        $this->factory->method('create')->willReturn($collection);
    }

    // -----------------------------------------------------------------
    // Wildcard semantics — null in either side means "match all"
    // -----------------------------------------------------------------

    public function testTwoWildcardRatesOverlap(): void
    {
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'sort_order' => 0]),
        ]);

        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'sort_order' => 0]);
        $conflicts = $this->detector->detect($candidate);

        $this->assertCount(1, $conflicts);
        $this->assertSame(2, $conflicts[0]['rate_id']);
        $this->assertStringContainsString('SAME sort_order', $conflicts[0]['reason']);
    }

    public function testDifferentSortOrdersStillFlagOverlapButNotAsConflict(): void
    {
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'sort_order' => 5]),
        ]);

        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'sort_order' => 10]);
        $conflicts = $this->detector->detect($candidate);

        $this->assertCount(1, $conflicts);
        $this->assertStringNotContainsString('SAME sort_order', $conflicts[0]['reason']);
    }

    // -----------------------------------------------------------------
    // Geographic exclusion stops the overlap
    // -----------------------------------------------------------------

    public function testDifferentCountriesNeverOverlap(): void
    {
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'country_code' => 'US']),
        ]);

        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'country_code' => 'GB']);
        $this->assertSame([], $this->detector->detect($candidate));
    }

    public function testCountryWildcardOverlapsAllCountries(): void
    {
        $this->stubExistingRates([
            // existing rule is wildcard country
            $this->buildRate(['rate_id' => 2, 'method_id' => 1]),
        ]);

        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'country_code' => 'GB']);
        $this->assertCount(1, $this->detector->detect($candidate));
    }

    // -----------------------------------------------------------------
    // Numeric range exclusion
    // -----------------------------------------------------------------

    public function testNonOverlappingWeightRangesAreSafe(): void
    {
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'weight_from' => 0,  'weight_to' => 5]),
        ]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'weight_from' => 5.01, 'weight_to' => 10]);
        $this->assertSame([], $this->detector->detect($candidate));
    }

    public function testTouchingWeightRangesDoOverlapAt5kg(): void
    {
        // 0-5 and 5-10 both include 5kg exactly → real overlap
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'weight_from' => 0, 'weight_to' => 5, 'sort_order' => 0]),
        ]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'weight_from' => 5, 'weight_to' => 10, 'sort_order' => 0]);
        $conflicts = $this->detector->detect($candidate);
        $this->assertCount(1, $conflicts);
    }

    public function testHalfOpenRangesOverlap(): void
    {
        // existing: weight 0+, candidate: weight up to 10 → both include 5kg
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'weight_from' => 0, 'weight_to' => null]),
        ]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'weight_from' => null, 'weight_to' => 10]);
        $this->assertCount(1, $this->detector->detect($candidate));
    }

    // -----------------------------------------------------------------
    // Postcode range exclusion (alphanumeric — UK postcodes)
    // -----------------------------------------------------------------

    public function testPostcodeRangesNonOverlapping(): void
    {
        // SW1A 0AA–SW1A 1ZZ vs SW1B 0AA–SW1B 9ZZ → disjoint
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'zip_from' => 'SW1A 0AA', 'zip_to' => 'SW1A 1ZZ']),
        ]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'zip_from' => 'SW1B 0AA', 'zip_to' => 'SW1B 9ZZ']);
        $this->assertSame([], $this->detector->detect($candidate));
    }

    public function testPostcodeRangesOverlapping(): void
    {
        // Both include SW1A 1AA
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'zip_from' => 'SW1A 0AA', 'zip_to' => 'SW1A 9ZZ']),
        ]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'zip_from' => 'SW1A 1AA', 'zip_to' => 'SW1A 1ZZ']);
        $this->assertCount(1, $this->detector->detect($candidate));
    }

    // -----------------------------------------------------------------
    // Customer group exclusion
    // -----------------------------------------------------------------

    public function testDisjointCustomerGroupsNeverOverlap(): void
    {
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'customer_group_id' => '1,2']),
        ]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'customer_group_id' => '3,4']);
        $this->assertSame([], $this->detector->detect($candidate));
    }

    public function testCustomerGroupIntersectionDoesOverlap(): void
    {
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'customer_group_id' => '1,2,3']),
        ]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'customer_group_id' => '3,4,5']);
        // both include group 3
        $this->assertCount(1, $this->detector->detect($candidate));
    }

    // -----------------------------------------------------------------
    // Shipping type — wildcard + specific value semantics
    // -----------------------------------------------------------------

    public function testDifferentShippingTypesAreDisjoint(): void
    {
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'shipping_type' => 'fragile']),
        ]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'oversized']);
        $this->assertSame([], $this->detector->detect($candidate));
    }

    public function testWildcardShippingTypeOverlapsSpecific(): void
    {
        // Wildcard rule applies to ALL types → overlaps with type-specific rule
        $this->stubExistingRates([
            $this->buildRate(['rate_id' => 2, 'method_id' => 1, 'sort_order' => 0]),  // no shipping_type = wildcard
        ]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1, 'shipping_type' => 'fragile', 'sort_order' => 0]);
        $conflicts = $this->detector->detect($candidate);
        $this->assertCount(1, $conflicts);
        $this->assertStringContainsString('SAME sort_order', $conflicts[0]['reason']);
    }

    // -----------------------------------------------------------------
    // Self-exclusion + bad inputs
    // -----------------------------------------------------------------

    public function testSelfNeverReportsAsConflict(): void
    {
        // Detector should pass rate_id != self to the collection filter,
        // and we trust the collection here. But to verify behavior we
        // confirm an empty result given no peers.
        $this->stubExistingRates([]);
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 1]);
        $this->assertSame([], $this->detector->detect($candidate));
    }

    public function testZeroMethodIdReturnsEmpty(): void
    {
        // No factory call expected — early return for orphan rules
        $this->factory->expects($this->never())->method('create');
        $candidate = $this->buildRate(['rate_id' => 1, 'method_id' => 0]);
        $this->assertSame([], $this->detector->detect($candidate));
    }
}
