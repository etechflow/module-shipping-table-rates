<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Csv;

use ETechFlow\ShippingTableRates\Model\Version\VersionRepository;
use ETechFlow\ShippingTableRates\Model\Method;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Import a CSV of rate rules into a method.
 *
 * Two import modes:
 *
 *   - REPLACE: snapshot existing rates → wipe → insert from CSV
 *   - APPEND:  insert from CSV alongside existing rates
 *
 * Validation is collected across the WHOLE file (not fail-on-first-row) so
 * the merchant gets a single error report they can act on. If any row fails,
 * NOTHING is committed — atomic import via a DB transaction.
 *
 * Result is a structured report containing per-row outcomes so the admin UI
 * can render "✓ Row 1-238 imported, ✗ Row 239: weight_from is not a valid
 * number, ✗ Row 240: ..."
 */
class CsvImporter
{
    public const MODE_REPLACE = 'replace';
    public const MODE_APPEND  = 'append';

    /**
     * Constructor.
     *
     * @param RateRowParser     $parser
     * @param VersionRepository $versionRepository
     * @param ResourceConnection $resource
     * @param LoggerInterface   $logger
     */
    public function __construct(
        private readonly RateRowParser $parser,
        private readonly VersionRepository $versionRepository,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Import rows from an open CSV file handle into a method.
     *
     * The handle is read until EOF; the caller is responsible for opening
     * and closing it. Designed this way so tests can pass `fopen('php://memory')`
     * without touching the filesystem.
     *
     * @param Method   $method
     * @param resource $csvHandle  Already-opened CSV file resource
     * @param string   $mode       'replace' | 'append'
     * @return ImportResult
     * @throws LocalizedException If the file is empty or the header is invalid.
     */
    public function import(Method $method, $csvHandle, string $mode = self::MODE_REPLACE): ImportResult
    {
        $methodId = $method->getMethodId();
        if ($methodId === null) {
            throw new LocalizedException(__('Save the method first before importing rates.'));
        }

        if (!is_resource($csvHandle)) {
            throw new LocalizedException(__('Invalid CSV file handle.'));
        }

        // Header row. The $escape '\\' is explicit because PHP 8.4 emits a
        // deprecation when fgetcsv is called without it (the language is
        // moving to '' as the default in PHP 9). Passing '\\' preserves the
        // historical behaviour exactly.
        rewind($csvHandle);
        $header = fgetcsv($csvHandle, 0, ',', '"', '\\');
        if ($header === false || empty($header)) {
            throw new LocalizedException(__('CSV file is empty or has no header row.'));
        }

        $headerMap = $this->buildHeaderMap($header);
        $this->validateHeaderHasRequiredColumns($headerMap);

        // Parse each row, collect errors + data separately
        $rowIndex = 1;  // 1-based so error messages match what the merchant sees in their editor
        $errorsByRow = [];
        $parsedRows  = [];

        while (($row = fgetcsv($csvHandle, 0, ',', '"', '\\')) !== false) {
            $rowIndex++;

            // Skip blank rows
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }

            $result = $this->parser->parse($headerMap, $row);
            if (isset($result['errors'])) {
                $errorsByRow[$rowIndex] = $result['errors'];
                continue;
            }

            $parsedRows[$rowIndex] = $result['data'];
        }

        if (!empty($errorsByRow)) {
            // Don't write anything if any row failed
            return ImportResult::failure($errorsByRow, count($parsedRows));
        }

        if (empty($parsedRows)) {
            return ImportResult::failure([], 0);
        }

        // Feature 5: split parsed rows into INSERT rows and DELETE rows.
        // Delete rows are identified by delete_row=true (the directive column,
        // not stored in the DB). They identify EXISTING rates to remove
        // instead of inserting new ones.
        $insertRows = [];
        $deleteRows = [];
        foreach ($parsedRows as $rowIndex => $data) {
            if (!empty($data['delete_row'])) {
                $deleteRows[$rowIndex] = $data;
            } else {
                $insertRows[$rowIndex] = $data;
            }
        }

        // All rows valid — snapshot + commit
        $this->versionRepository->snapshot($method, "Pre-CSV-import ({$mode})");

        $connection = $this->resource->getConnection();
        $rateTable  = $this->resource->getTableName('etechflow_str_rate');

        $deletedCount = 0;
        $warnings     = [];

        $connection->beginTransaction();
        try {
            if ($mode === self::MODE_REPLACE) {
                $connection->delete($rateTable, ['method_id = ?' => $methodId]);
                // Delete rows in REPLACE mode are no-ops: the table for this
                // method was just wiped, so there's nothing left to match.
                // Surface a warning per delete row so the merchant notices
                // their intent didn't apply (typical mistake: using
                // delete_row=1 with the wrong import mode selected).
                foreach ($deleteRows as $rowIndex => $_data) {
                    $warnings[$rowIndex] = 'delete_row=1 in REPLACE mode is a no-op — the table was wiped before this row ran. Use APPEND mode if you want selective deletion.';
                }
            } else {
                // APPEND mode: execute each delete row against existing data.
                foreach ($deleteRows as $rowIndex => $data) {
                    $deleted = $this->deleteMatchingRates($methodId, $data);
                    if ($deleted === 0) {
                        $warnings[$rowIndex] = 'delete_row=1 matched no existing rate — nothing to delete.';
                    }
                    $deletedCount += $deleted;
                }
            }

            foreach ($insertRows as $data) {
                $data['method_id'] = $methodId;
                // Convert empty string to null for nullable string columns
                $insertable = $this->prepareForInsert($data);
                $connection->insert($rateTable, $insertable);
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error(
                'ETechFlow_ShippingTableRates: CSV import transaction rolled back.',
                ['method_id' => $methodId, 'exception' => $e->getMessage()]
            );
            throw new LocalizedException(__('Import failed: %1', $e->getMessage()));
        }

        return ImportResult::success(count($insertRows), $deletedCount, $warnings);
    }

    /**
     * Execute the DELETE for a single delete-row data array. Matches
     * existing rates on every identifying condition column (NULL → IS NULL,
     * non-NULL → =). Returns the number of rates deleted.
     *
     * Identifying columns are everything that goes into the rate's "shape":
     * destination + cart conditions + customer-group + shipping-type. Rate
     * components (base/per_product/per_kg/percent) are NOT part of the
     * identity because two rates with the same conditions but different
     * rates would be a conflict anyway (caught by ConflictDetector).
     *
     * @param int                  $methodId
     * @param array<string, mixed> $data Parsed delete row
     * @return int Number of rates deleted
     */
    private function deleteMatchingRates(int $methodId, array $data): int
    {
        $connection = $this->resource->getConnection();
        $rateTable  = $this->resource->getTableName('etechflow_str_rate');

        $where = ['method_id = ?' => $methodId];

        // Identifying columns — CSV name → DB column. customer_group_ids in
        // the CSV maps to customer_group_id in the DB (column is singular but
        // stores a comma-separated list).
        $columnMap = [
            'country_code'       => 'country_code',
            'region_code'        => 'region_code',
            'city'               => 'city',
            'zip_from'           => 'zip_from',
            'zip_to'             => 'zip_to',
            'weight_from'        => 'weight_from',
            'weight_to'          => 'weight_to',
            'qty_from'           => 'qty_from',
            'qty_to'             => 'qty_to',
            'subtotal_from'      => 'subtotal_from',
            'subtotal_to'        => 'subtotal_to',
            'customer_group_ids' => 'customer_group_id',
            'shipping_type'      => 'shipping_type',
        ];

        foreach ($columnMap as $csvKey => $dbColumn) {
            $value = $data[$csvKey] ?? null;
            if ($value === null || $value === '') {
                $where["{$dbColumn} IS NULL"] = null;
            } else {
                $where["{$dbColumn} = ?"] = $value;
            }
        }

        // Strip the IS NULL clauses (they don't take a placeholder) and
        // pass the rest through Magento's quote-aware delete builder.
        $bindings = [];
        $clauses  = [];
        foreach ($where as $expr => $val) {
            if ($val === null) {
                $clauses[] = str_replace(' = ?', '', $expr);  // IS NULL stays as-is
            } else {
                $clauses[] = $expr;
                $bindings[] = $val;
            }
        }
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $connection->quoteIdentifier($rateTable),
            implode(' AND ', $clauses)
        );
        return (int) $connection->query($sql, $bindings)->rowCount();
    }

    /**
     * Build a column-key → CSV-column-index map from the file's header row.
     * Unknown columns are skipped silently (forward compatibility); required
     * columns being absent is caught by validateHeaderHasRequiredColumns().
     *
     * @param array $headerRow
     * @return array<string, int>
     */
    private function buildHeaderMap(array $headerRow): array
    {
        $known = CsvSchema::getColumnKeys();
        $map = [];
        foreach ($headerRow as $i => $name) {
            $name = strtolower(trim((string) $name));
            if (in_array($name, $known, true)) {
                $map[$name] = $i;
            }
        }
        return $map;
    }

    /**
     * The minimum-viable header: only `rate_base` is strictly required
     * (without it the row has no charge). Everything else can default.
     *
     * @param array<string, int> $headerMap
     * @throws LocalizedException
     */
    private function validateHeaderHasRequiredColumns(array $headerMap): void
    {
        if (!isset($headerMap['rate_base'])) {
            throw new LocalizedException(
                __('CSV header is missing the required "rate_base" column. Download the template via Export to see the full schema.')
            );
        }
    }

    /**
     * Coerce nulls + booleans for DB insert. Some DB drivers reject `null`
     * for non-nullable string columns; convert to empty strings for those
     * if the schema actually allows it. (Our schema makes all condition
     * columns nullable, so null is fine here.)
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function prepareForInsert(array $row): array
    {
        $insertable = $row;

        // Strip the delete_row directive — it's a CSV-only flag, not a DB
        // column. Feature 5 splits delete vs insert rows BEFORE calling
        // prepareForInsert, but a stray 0/blank value still lingers on
        // insert rows; would otherwise crash the INSERT with "unknown column".
        unset($insertable['delete_row']);

        // CSV uses `customer_group_ids` (plural — matches Amasty convention
        // and the v1.1 method-level column). The rate DB column is
        // `customer_group_id` (singular — pre-existing). Rename the key so
        // the INSERT references the correct column. Without this an import
        // crashes with "Unknown column 'customer_group_ids' in INSERT INTO"
        // the moment any CSV row reaches the DB layer (caught by the v1.1
        // performance benchmark — unit tests mock the adapter).
        if (array_key_exists('customer_group_ids', $insertable)) {
            $insertable['customer_group_id'] = $insertable['customer_group_ids'];
            unset($insertable['customer_group_ids']);
        }

        // is_active: cast bool to tinyint
        if (array_key_exists('is_active', $insertable)) {
            $insertable['is_active'] = $insertable['is_active'] ? 1 : 0;
        }

        // Numeric defaults — schema says these columns are NOT NULL default 0
        foreach (['rate_base', 'rate_per_product', 'rate_per_kg', 'rate_percent', 'sort_order'] as $col) {
            if (!array_key_exists($col, $insertable) || $insertable[$col] === null) {
                $insertable[$col] = 0;
            }
        }

        // Weight unit conversion: schema says NOT NULL default 1.0. Empty CSV
        // value or absent column → 1.0 (= no conversion). Negative or zero
        // would divide-by-zero / break the formula, so coerce to 1.0 too.
        if (!array_key_exists('weight_unit_conversion_rate', $insertable)
            || $insertable['weight_unit_conversion_rate'] === null
            || (float) $insertable['weight_unit_conversion_rate'] <= 0.0
        ) {
            $insertable['weight_unit_conversion_rate'] = 1.0;
        }

        return $insertable;
    }
}
