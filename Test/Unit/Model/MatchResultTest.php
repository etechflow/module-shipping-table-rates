<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Test\Unit\Model;

use ETechFlow\ShippingTableRates\Model\MatchResult;
use ETechFlow\ShippingTableRates\Model\Rate;
use PHPUnit\Framework\TestCase;

/**
 * Covers the value-object behaviour of MatchResult — specifically the
 * Amasty-parity `{day}`/`{name}` template variable substitution and the
 * existing longest-delivery-days aggregate.
 *
 * Rate stubs are built the same way RateMatcherTest builds them: mock
 * getData() so the real typed accessors keep their semantics.
 */
class MatchResultTest extends TestCase
{
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

    // ---------------------------------------------------------------------
    // interpolateMethodName — Feature 2 ({day}/{name} template variables)
    // ---------------------------------------------------------------------

    public function testTemplateWithNoPlaceholdersReturnedUnchanged(): void
    {
        // Caller relies on template === result to detect "no placeholders"
        // and fall back to the legacy "(X days)" suffix.
        $result = new MatchResult([$this->buildRate(['delivery_days' => 3])], 10.0);
        $template = 'UK Express Shipping';
        $this->assertSame($template, $result->interpolateMethodName($template));
    }

    public function testDayPlaceholderUsesDeliveryLabelWhenSet(): void
    {
        $result = new MatchResult([
            $this->buildRate([
                'delivery_days'  => 3,
                'delivery_label' => 'to Canada, 5 working days',
            ]),
        ], 10.0);
        $this->assertSame(
            'Express to Canada, 5 working days',
            $result->interpolateMethodName('Express {day}')
        );
    }

    public function testDayPlaceholderFallsBackToDeliveryDaysIntWhenLabelUnset(): void
    {
        $result = new MatchResult([
            $this->buildRate(['delivery_days' => 5]),
        ], 10.0);
        $this->assertSame(
            'Delivery in 5 days',
            $result->interpolateMethodName('Delivery in {day} days')
        );
    }

    public function testDayPlaceholderEmptyWhenNeitherLabelNorDaysSet(): void
    {
        // No delivery_label, no delivery_days — substitution is empty and the
        // surrounding whitespace is tidied.
        $result = new MatchResult([
            $this->buildRate([]),
        ], 10.0);
        $this->assertSame(
            'Express days',
            $result->interpolateMethodName('Express {day} days')
        );
    }

    public function testNamePlaceholderUsesNameDelivery(): void
    {
        $result = new MatchResult([
            $this->buildRate([
                'name_delivery' => 'Tracked 24',
            ]),
        ], 10.0);
        $this->assertSame(
            'Royal Mail Tracked 24',
            $result->interpolateMethodName('Royal Mail {name}')
        );
    }

    public function testBothPlaceholdersInSameTemplate(): void
    {
        $result = new MatchResult([
            $this->buildRate([
                'delivery_label' => '2',
                'name_delivery'  => 'Tracked',
            ]),
        ], 10.0);
        $this->assertSame(
            'Royal Mail Tracked (2 days)',
            $result->interpolateMethodName('Royal Mail {name} ({day} days)')
        );
    }

    public function testLongestDeliveryRateChosenForInterpolation(): void
    {
        // Mixed-type cart with two winning rates: 2-day and 5-day. The 5-day
        // rate is the customer-honest choice because the slowest leg sets the
        // expectation, so its labels should drive interpolation.
        $fastRate = $this->buildRate([
            'delivery_days'  => 2,
            'delivery_label' => 'next day-ish',
            'name_delivery'  => 'Standard',
        ]);
        $slowRate = $this->buildRate([
            'delivery_days'  => 5,
            'delivery_label' => 'fragile route',
            'name_delivery'  => 'Fragile',
        ]);
        $result = new MatchResult([$fastRate, $slowRate], 20.0);
        $this->assertSame(
            'Fragile in fragile route',
            $result->interpolateMethodName('{name} in {day}')
        );
    }

    public function testNoWinningRatesProducesEmptySubstitution(): void
    {
        // Theoretical edge case: a MatchResult constructed without winners.
        // Substitution is empty and trailing whitespace is tidied.
        $result = new MatchResult([], 0.0);
        $this->assertSame(
            'Express',
            $result->interpolateMethodName('Express {day} {name}')
        );
    }

    public function testEmptyDeliveryLabelTreatedAsUnsetAndFallsBackToDays(): void
    {
        // Rate::getDeliveryLabel returns null for both NULL and empty string —
        // verify the fallback to integer days kicks in.
        $result = new MatchResult([
            $this->buildRate([
                'delivery_days'  => 4,
                'delivery_label' => '',
            ]),
        ], 10.0);
        $this->assertSame(
            'Delivery 4',
            $result->interpolateMethodName('Delivery {day}')
        );
    }

    public function testInteriorDoubleSpaceIsCollapsed(): void
    {
        // "{day}  {name}" with both empty would yield three spaces; tidy
        // collapses runs of whitespace to a single space and trims.
        $result = new MatchResult([
            $this->buildRate([]),
        ], 10.0);
        $this->assertSame(
            'Express delivery',
            $result->interpolateMethodName('Express {day}  {name} delivery')
        );
    }

    // ---------------------------------------------------------------------
    // getLongestDeliveryDays — kept as a regression net
    // ---------------------------------------------------------------------

    public function testLongestDeliveryDaysReturnsMax(): void
    {
        $result = new MatchResult([
            $this->buildRate(['delivery_days' => 2]),
            $this->buildRate(['delivery_days' => 7]),
            $this->buildRate(['delivery_days' => 3]),
        ], 0.0);
        $this->assertSame(7, $result->getLongestDeliveryDays());
    }

    public function testLongestDeliveryDaysIsNullWhenNoRatesCarryIt(): void
    {
        $result = new MatchResult([$this->buildRate([])], 0.0);
        $this->assertNull($result->getLongestDeliveryDays());
    }
}
