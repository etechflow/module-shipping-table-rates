<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Csv;

/**
 * Result of a CsvImporter::import() call. Immutable. Carries either a
 * success message or a structured per-row error report — never both.
 *
 * The admin controller renders this directly: green flash + count on
 * success, table of per-row errors on failure.
 */
class ImportResult
{
    /**
     * @param bool                  $success
     * @param int                   $rowsImported
     * @param int                   $rowsDeleted   Rows where delete_row=1 actually removed an existing rate (Feature 5)
     * @param int                   $rowsFailed
     * @param array<int, string[]>  $errorsByRow   Keyed by 1-based row number — present only on failure
     * @param array<int, string>    $warnings      Keyed by 1-based row number — non-fatal advisories (e.g. delete row matched no existing rate)
     */
    private function __construct(
        public readonly bool $success,
        public readonly int $rowsImported,
        public readonly int $rowsDeleted,
        public readonly int $rowsFailed,
        public readonly array $errorsByRow,
        public readonly array $warnings = []
    ) {
    }

    /**
     * @param int                $rowsImported
     * @param int                $rowsDeleted Feature 5: deletes that succeeded
     * @param array<int, string> $warnings    Feature 5: non-fatal per-row advisories (e.g. "delete row matched no existing rate")
     * @return self
     */
    public static function success(int $rowsImported, int $rowsDeleted = 0, array $warnings = []): self
    {
        return new self(true, $rowsImported, $rowsDeleted, 0, [], $warnings);
    }

    /**
     * @param array<int, string[]> $errorsByRow
     * @param int $rowsThatWouldHaveImported
     * @return self
     */
    public static function failure(array $errorsByRow, int $rowsThatWouldHaveImported): self
    {
        return new self(false, 0, 0, count($errorsByRow), $errorsByRow, []);
    }

    /**
     * Convenience for flashing a user-friendly summary.
     *
     * @return string
     */
    public function getSummary(): string
    {
        if ($this->success) {
            $parts = [];
            if ($this->rowsImported > 0) {
                $parts[] = "Imported {$this->rowsImported} rate rule" . ($this->rowsImported === 1 ? '' : 's');
            }
            if ($this->rowsDeleted > 0) {
                $parts[] = "deleted {$this->rowsDeleted}";
            }
            if (empty($parts)) {
                // No inserts, no deletes — possible if the whole CSV was
                // delete rows that matched nothing.
                $parts[] = 'No rate rules changed';
            }
            $summary = implode(', ', $parts) . '.';
            if (!empty($this->warnings)) {
                $count = count($this->warnings);
                $summary .= " {$count} warning" . ($count === 1 ? '' : 's') . '.';
            }
            return $summary;
        }
        if ($this->rowsFailed === 0) {
            return 'CSV was empty — nothing to import.';
        }
        return "Import failed: {$this->rowsFailed} row" . ($this->rowsFailed === 1 ? '' : 's') . ' had errors. Fix and re-upload.';
    }
}
