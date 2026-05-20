<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Test\Unit\Model\Csv;

use ETechFlow\ShippingTableRates\Model\Csv\CsvImporter;
use ETechFlow\ShippingTableRates\Model\Csv\CsvSchema;
use ETechFlow\ShippingTableRates\Model\Csv\RateRowParser;
use ETechFlow\ShippingTableRates\Model\Method;
use ETechFlow\ShippingTableRates\Model\Version\VersionRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Statement\Pdo\Mysql as PdoStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the CSV importer's transaction + per-row dispatch behaviour.
 *
 * Focus is on Feature 5 — the `delete_row` directive — because the rest of
 * the importer (insert path, header validation, error collection) is
 * exercised by RateRowParserTest and a manual smoke in the live admin.
 *
 * The adapter is mocked: we record the sequence of insert / delete / query
 * calls and verify the importer dispatched the right work.
 */
class CsvImporterTest extends TestCase
{
    private RateRowParser $parser;
    private VersionRepository|MockObject $versionRepository;
    private ResourceConnection|MockObject $resource;
    private AdapterInterface|MockObject $adapter;
    private LoggerInterface|MockObject $logger;
    private CsvImporter $importer;

    /** Recorded calls so test assertions can verify what hit the DB. */
    private array $inserts = [];
    private array $deletes = [];
    private array $rawQueries = [];

    /** How many rows each raw DELETE query should report as deleted. */
    private int $nextDeleteRowCount = 0;

    protected function setUp(): void
    {
        $this->parser = new RateRowParser();  // real parser — pure logic
        $this->versionRepository = $this->createMock(VersionRepository::class);
        $this->resource          = $this->createMock(ResourceConnection::class);
        $this->adapter           = $this->createMock(AdapterInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);

        $this->resource->method('getConnection')->willReturn($this->adapter);
        $this->resource->method('getTableName')->willReturnArgument(0);
        $this->adapter->method('quoteIdentifier')->willReturnArgument(0);

        // Record inserts so assertions can inspect them.
        $this->adapter->method('insert')->willReturnCallback(function (string $table, array $row): int {
            $this->inserts[] = ['table' => $table, 'row' => $row];
            return 1;
        });
        // Record bind-array deletes too (Magento style: ['col = ?' => $value]).
        $this->adapter->method('delete')->willReturnCallback(function (string $table, $where): int {
            $this->deletes[] = ['table' => $table, 'where' => $where];
            return 1;
        });
        // Record raw DELETE queries — Feature 5 uses this for nullable matching.
        $this->adapter->method('query')->willReturnCallback(function (string $sql, array $bind): PdoStatement {
            $this->rawQueries[] = ['sql' => $sql, 'bind' => $bind];
            $stmt = $this->createMock(PdoStatement::class);
            $stmt->method('rowCount')->willReturn($this->nextDeleteRowCount);
            $this->nextDeleteRowCount = 0;  // reset after each query
            return $stmt;
        });

        $this->importer = new CsvImporter(
            $this->parser,
            $this->versionRepository,
            $this->resource,
            $this->logger
        );
    }

    /**
     * Open an in-memory CSV file from an array of header keys + rows.
     *
     * @param string[]                  $headers Column keys
     * @param array<int, array<string, string>> $rows  Per-row key=>value maps
     * @return resource
     */
    private function openCsv(array $headers, array $rows)
    {
        $h = fopen('php://memory', 'r+');
        fputcsv($h, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            $ordered = [];
            foreach ($headers as $key) {
                $ordered[] = $row[$key] ?? '';
            }
            fputcsv($h, $ordered, ',', '"', '\\');
        }
        rewind($h);
        return $h;
    }

    private function buildMethod(int $id = 7): Method
    {
        $method = $this->getMockBuilder(Method::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $method->method('getData')->willReturnCallback(
            static fn($key) => $key === 'method_id' ? $id : null
        );
        return $method;
    }

    public function testAppendDeleteEmitsRawQueryAgainstRateTable(): void
    {
        $headers = CsvSchema::getColumnKeys();
        $csv = $this->openCsv($headers, [
            ['country_code' => 'GB', 'weight_from' => '0', 'weight_to' => '5', 'delete_row' => '1'],
        ]);
        $this->nextDeleteRowCount = 1;

        $result = $this->importer->import($this->buildMethod(), $csv, CsvImporter::MODE_APPEND);

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->rowsImported);
        $this->assertSame(1, $result->rowsDeleted);
        $this->assertEmpty($result->warnings);
        $this->assertCount(1, $this->rawQueries);
        $this->assertStringContainsString('DELETE FROM', $this->rawQueries[0]['sql']);
        $this->assertStringContainsString('etechflow_str_rate', $this->rawQueries[0]['sql']);
        // method_id + country_code + weight_from + weight_to in bindings
        $this->assertContains(7, $this->rawQueries[0]['bind']);
        $this->assertContains('GB', $this->rawQueries[0]['bind']);
    }

    public function testAppendDeleteWithNullColumnsEmitsIsNullClauses(): void
    {
        // Empty country_code, region_code etc. → DELETE WHERE country_code IS NULL
        // for those columns, instead of binding placeholder values.
        $headers = CsvSchema::getColumnKeys();
        $csv = $this->openCsv($headers, [
            ['weight_from' => '5', 'weight_to' => '10', 'delete_row' => '1'],
        ]);
        $this->nextDeleteRowCount = 2;

        $result = $this->importer->import($this->buildMethod(), $csv, CsvImporter::MODE_APPEND);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->rowsDeleted);
        $sql = $this->rawQueries[0]['sql'];
        $this->assertStringContainsString('country_code IS NULL', $sql);
        $this->assertStringContainsString('shipping_type IS NULL', $sql);
        $this->assertStringContainsString('customer_group_id IS NULL', $sql);
    }

    public function testAppendDeleteNoMatchEmitsWarning(): void
    {
        $headers = CsvSchema::getColumnKeys();
        $csv = $this->openCsv($headers, [
            ['country_code' => 'ZZ', 'delete_row' => '1'],
        ]);
        $this->nextDeleteRowCount = 0;  // No matching rate

        $result = $this->importer->import($this->buildMethod(), $csv, CsvImporter::MODE_APPEND);

        $this->assertTrue($result->success, 'No-match delete is a warning, not a failure');
        $this->assertSame(0, $result->rowsDeleted);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('matched no existing rate', array_values($result->warnings)[0]);
    }

    public function testAppendMixedInsertsAndDeletes(): void
    {
        $headers = CsvSchema::getColumnKeys();
        $csv = $this->openCsv($headers, [
            ['country_code' => 'GB', 'rate_base' => '5.99'],
            ['country_code' => 'US', 'rate_base' => '7.50'],
            ['country_code' => 'DE', 'weight_from' => '0', 'weight_to' => '5', 'delete_row' => '1'],
        ]);
        $this->nextDeleteRowCount = 1;

        $result = $this->importer->import($this->buildMethod(), $csv, CsvImporter::MODE_APPEND);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->rowsImported);
        $this->assertSame(1, $result->rowsDeleted);
        $this->assertCount(2, $this->inserts);
        $this->assertCount(1, $this->rawQueries);
        // delete_row must NOT leak into INSERTed rows
        foreach ($this->inserts as $insert) {
            $this->assertArrayNotHasKey('delete_row', $insert['row']);
        }
    }

    public function testReplaceModeDeleteRowsBecomeWarnings(): void
    {
        // In REPLACE mode the whole method's rate table is wiped first,
        // so delete rows are inherently no-ops. They should surface as
        // warnings so the merchant notices their intent didn't apply.
        $headers = CsvSchema::getColumnKeys();
        $csv = $this->openCsv($headers, [
            ['country_code' => 'GB', 'rate_base' => '5.99'],
            ['country_code' => 'US', 'delete_row' => '1'],
            ['country_code' => 'DE', 'delete_row' => '1'],
        ]);

        $result = $this->importer->import($this->buildMethod(), $csv, CsvImporter::MODE_REPLACE);

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->rowsImported);
        $this->assertSame(0, $result->rowsDeleted);
        $this->assertCount(2, $result->warnings, 'Both delete rows in REPLACE mode should warn');
        foreach ($result->warnings as $warning) {
            $this->assertStringContainsString('REPLACE mode', $warning);
        }
        // The REPLACE-mode wipe goes through ::delete (bind array), not ::query.
        $this->assertCount(1, $this->deletes);
        $this->assertCount(0, $this->rawQueries);
    }

    public function testDeleteRowMatchesAcrossEveryIdentifyingColumn(): void
    {
        // Smoke test: a fully-specified delete row binds every identifying
        // column. Guards against accidentally dropping one from the where map.
        $headers = CsvSchema::getColumnKeys();
        $csv = $this->openCsv($headers, [
            [
                'country_code'       => 'GB',
                'region_code'        => 'ENG',
                'city'               => 'London',
                'zip_from'           => 'SW1',
                'zip_to'             => 'SW19',
                'weight_from'        => '0',
                'weight_to'          => '5',
                'qty_from'           => '1',
                'qty_to'             => '10',
                'subtotal_from'      => '50',
                'subtotal_to'        => '500',
                'customer_group_ids' => '1,2',
                'shipping_type'      => 'fragile',
                'delete_row'         => '1',
            ],
        ]);
        $this->nextDeleteRowCount = 1;

        $this->importer->import($this->buildMethod(), $csv, CsvImporter::MODE_APPEND);

        // Each identifying value should appear in the SQL bindings.
        $bind = $this->rawQueries[0]['bind'];
        $this->assertContains('GB', $bind);
        $this->assertContains('ENG', $bind);
        $this->assertContains('London', $bind);
        $this->assertContains('SW1', $bind);
        $this->assertContains('SW19', $bind);
        $this->assertContains('fragile', $bind);
        // method_id is always bound first
        $this->assertSame(7, $bind[0]);
    }
}
