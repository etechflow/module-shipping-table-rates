<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Test\Unit\Model\Csv;

use ETechFlow\ShippingTableRates\Model\Csv\CsvSchema;
use ETechFlow\ShippingTableRates\Model\Csv\RateRowParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-row CSV validator + caster.
 *
 * Focuses on: type-coercion correctness, friendly error messages for the
 * common merchant mistakes (decimal commas, missing values, bad ranges),
 * cross-column validations (range coherence, all-zero-rate warning).
 */
class RateRowParserTest extends TestCase
{
    private RateRowParser $parser;

    /** Header map matching the full schema in canonical column order. */
    private array $fullHeaderMap;

    protected function setUp(): void
    {
        $this->parser = new RateRowParser();

        $this->fullHeaderMap = [];
        $i = 0;
        foreach (CsvSchema::getColumnKeys() as $key) {
            $this->fullHeaderMap[$key] = $i++;
        }
    }

    /**
     * Build a CSV row indexed by column position, with the given column values
     * filled in and everything else empty.
     */
    private function buildRow(array $values): array
    {
        $row = array_fill(0, count($this->fullHeaderMap), '');
        foreach ($values as $key => $val) {
            if (!isset($this->fullHeaderMap[$key])) {
                continue;
            }
            $row[$this->fullHeaderMap[$key]] = $val;
        }
        return $row;
    }

    // -----------------------------------------------------------------
    // Happy paths
    // -----------------------------------------------------------------

    public function testValidMinimalRow(): void
    {
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base' => '5.00',
        ]));

        $this->assertArrayHasKey('data', $result);
        $this->assertSame(5.0, $result['data']['rate_base']);
        // Nullable conditions default to null
        $this->assertNull($result['data']['country_code']);
        $this->assertNull($result['data']['weight_from']);
        $this->assertTrue($result['data']['is_active'], 'Empty is_active should default to true');
    }

    public function testFullyPopulatedRow(): void
    {
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'country_code'       => 'GB',
            'region_code'        => 'ENG',
            'city'               => 'London',
            'zip_from'           => 'SW1A 1AA',
            'zip_to'             => 'SW1Z 9ZZ',
            'weight_from'        => '0.5',
            'weight_to'          => '5.0',
            'qty_from'           => '1',
            'qty_to'             => '10',
            'subtotal_from'      => '20',
            'subtotal_to'        => '200',
            'customer_group_ids' => '1,3',
            'shipping_type'      => 'fragile',
            'rate_base'          => '5.99',
            'rate_per_product'   => '0.50',
            'rate_per_kg'        => '1.20',
            'rate_percent'       => '2.5',
            'delivery_days'      => '2',
            'comment'            => 'Includes signed-for delivery',
            'sort_order'         => '10',
            'is_active'          => 'yes',
        ]));

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('GB', $result['data']['country_code']);
        $this->assertSame(0.5, $result['data']['weight_from']);
        $this->assertSame(5.99, $result['data']['rate_base']);
        $this->assertTrue($result['data']['is_active']);
    }

    // -----------------------------------------------------------------
    // Type coercion
    // -----------------------------------------------------------------

    public function testEuropeanDecimalCommaAccepted(): void
    {
        // "5,99" instead of "5.99" — common European spreadsheet format
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base' => '5,99',
        ]));
        $this->assertSame(5.99, $result['data']['rate_base']);
    }

    public function testThousandsSeparatorAccepted(): void
    {
        // "1,234.56" — comma as thousands separator
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base'     => '5.00',
            'subtotal_from' => '1,234.56',
        ]));
        $this->assertSame(1234.56, $result['data']['subtotal_from']);
    }

    public function testYesNoBoolean(): void
    {
        foreach (['yes', 'YES', 'true', '1', 'y', 'on'] as $truthy) {
            $r = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
                'rate_base' => '5.00',
                'is_active' => $truthy,
            ]));
            $this->assertTrue($r['data']['is_active'], "'{$truthy}' should be truthy");
        }
        foreach (['no', 'false', '0', 'n', 'off'] as $falsy) {
            $r = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
                'rate_base' => '5.00',
                'is_active' => $falsy,
            ]));
            $this->assertFalse($r['data']['is_active'], "'{$falsy}' should be falsy");
        }
    }

    // -----------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------

    public function testInvalidIntFails(): void
    {
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base'     => '5.00',
            'delivery_days' => 'tomorrow',
        ]));
        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('delivery_days', $result['errors'][0]);
    }

    public function testInvalidFloatFails(): void
    {
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base' => 'free',
        ]));
        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('rate_base', $result['errors'][0]);
    }

    public function testInvalidBoolFails(): void
    {
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base' => '5.00',
            'is_active' => 'maybe',
        ]));
        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString('is_active', $result['errors'][0]);
    }

    // -----------------------------------------------------------------
    // Cross-column validations
    // -----------------------------------------------------------------

    public function testWeightFromAboveWeightToFails(): void
    {
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base'   => '5.00',
            'weight_from' => '10',
            'weight_to'   => '5',
        ]));
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn($e) => str_contains($e, 'weight_from')),
            'Expected an error mentioning weight_from'
        );
    }

    public function testQtyRangeInverted(): void
    {
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base' => '5.00',
            'qty_from'  => '10',
            'qty_to'    => '5',
        ]));
        $this->assertArrayHasKey('errors', $result);
    }

    public function testZipFromGreaterThanZipToFails(): void
    {
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base' => '5.00',
            'zip_from'  => 'SW1Z 9ZZ',
            'zip_to'    => 'SW1A 1AA',
        ]));
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn($e) => str_contains($e, 'zip_from')),
            'Expected an error about zip_from > zip_to'
        );
    }

    public function testAllZeroRateWarning(): void
    {
        // No rate_base + no other components → all zero
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'is_active' => 'yes',
        ]));
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn($e) => str_contains($e, 'all four rate components')),
            'Expected the all-zero warning'
        );
    }

    public function testZeroRateSuppressedByTinyBase(): void
    {
        // Workaround documented in the parser error message
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base' => '0.001',
        ]));
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayNotHasKey('errors', $result);
    }

    // -----------------------------------------------------------------
    // Reachability — parser collects ALL errors per row, not just first
    // -----------------------------------------------------------------

    public function testMultipleErrorsCollectedFromSameRow(): void
    {
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'rate_base'     => 'notanumber',
            'delivery_days' => 'tomorrow',
            'is_active'     => 'maybe',
        ]));
        $this->assertArrayHasKey('errors', $result);
        $this->assertGreaterThanOrEqual(3, count($result['errors']), 'Expected at least 3 errors collected');
    }

    // -----------------------------------------------------------------
    // Feature 5: delete_row directive
    // -----------------------------------------------------------------

    public function testDeleteRowSkipsAllZeroComponentsValidation(): void
    {
        // Normally a row with all four rate components zero would error
        // ("all four rate components are zero — this rule would always
        // charge £0"). A delete row legitimately carries no rate amounts
        // (it just identifies an existing rate to remove), so the check
        // is skipped.
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'country_code' => 'GB',
            'weight_from'  => '0',
            'weight_to'    => '5',
            'delete_row'   => '1',
            // Deliberately omit rate_base / per_product / per_kg / percent
        ]));
        $this->assertArrayHasKey('data', $result, 'Delete row should parse without errors');
        $this->assertTrue((bool) $result['data']['delete_row']);
    }

    public function testDeleteRowStillValidatesRangeOrdering(): void
    {
        // The row's identifying conditions must be coherent even when
        // delete_row=1 — otherwise the merchant could ship a CSV that
        // silently fails to delete because the impossible range matches
        // nothing. weight_from > weight_to is still an error.
        $result = $this->parser->parse($this->fullHeaderMap, $this->buildRow([
            'weight_from' => '10',
            'weight_to'   => '5',
            'delete_row'  => 'yes',
        ]));
        $this->assertArrayHasKey('errors', $result);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn($e) => str_contains($e, 'weight_from')),
            'Expected the weight-range error'
        );
    }
}
