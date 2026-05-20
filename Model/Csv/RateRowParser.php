<?php

declare(strict_types=1);

namespace ETechFlow\ShippingTableRates\Model\Csv;

/**
 * Parses + validates a single CSV row into a database-ready associative
 * array. Returns errors (rather than throwing) so the importer can collect
 * issues across the whole file and present them all at once — much better
 * UX than fail-on-first-error.
 */
class RateRowParser
{
    /**
     * Parse a single row, given the header layout from the CSV file's first
     * row. Returns either ['data' => [...]] on success, or
     * ['errors' => ['col_x: reason', ...]] on failure.
     *
     * @param array  $headerMap Column-key => column-index (0-based)
     * @param array  $row       Raw CSV row (indexed array of strings)
     * @return array{data?: array<string, mixed>, errors?: string[]}
     */
    public function parse(array $headerMap, array $row): array
    {
        $errors = [];
        $data   = [];

        foreach (CsvSchema::COLUMNS as $key => $meta) {
            $index = $headerMap[$key] ?? null;
            $raw   = $index !== null ? trim((string) ($row[$index] ?? '')) : '';

            // Empty values mean "use default / NULL" — only certain columns must be present
            if ($raw === '') {
                $data[$key] = $this->emptyDefault($meta['type'], $key);
                continue;
            }

            try {
                $data[$key] = $this->castValue($raw, $meta['type'], $key);
            } catch (\InvalidArgumentException $e) {
                $errors[] = "{$key}: {$e->getMessage()}";
            }
        }

        // Cross-column validations — these are the ones that cause cryptic
        // "no rate applies" mysteries at checkout if not caught here
        if (isset($data['zip_from'], $data['zip_to']) && $data['zip_from'] !== null && $data['zip_to'] !== null) {
            $norm = static fn($s) => strtoupper(str_replace(' ', '', (string) $s));
            if (strcmp($norm($data['zip_from']), $norm($data['zip_to'])) > 0) {
                $errors[] = 'zip_from is greater than zip_to (the range is empty — no postcode can match)';
            }
        }

        foreach (['weight', 'qty', 'subtotal'] as $field) {
            $from = $data["{$field}_from"] ?? null;
            $to   = $data["{$field}_to"]   ?? null;
            if ($from !== null && $to !== null && $from > $to) {
                $errors[] = "{$field}_from ({$from}) is greater than {$field}_to ({$to}) — the range is empty";
            }
        }

        // Delete rows don't carry rate components — the row identifies an
        // EXISTING rate to remove. Skip the zero-components and conversion
        // checks (a non-zero is suspicious for a delete row, but we don't
        // refuse it; merchants may have a row that lived as a normal rate
        // and is now being deleted with the same shape).
        $isDeleteRow = !empty($data['delete_row']);

        if (
            !$isDeleteRow
            && ($data['rate_base'] ?? 0) == 0
            && ($data['rate_per_product'] ?? 0) == 0
            && ($data['rate_per_kg'] ?? 0) == 0
            && ($data['rate_percent'] ?? 0) == 0
        ) {
            $errors[] = 'all four rate components are zero — this rule would always charge £0; if intentional, set rate_base=0.001 to suppress this warning';
        }

        // weight_unit_conversion_rate must be positive when explicitly given.
        // The CsvImporter coerces empty/null → 1.0 (no conversion); a
        // merchant-supplied zero or negative would silently break the per-kg
        // formula at checkout, so flag it as an error here.
        if (array_key_exists('weight_unit_conversion_rate', $data)
            && $data['weight_unit_conversion_rate'] !== null
            && $data['weight_unit_conversion_rate'] <= 0
        ) {
            $errors[] = "weight_unit_conversion_rate ({$data['weight_unit_conversion_rate']}) must be greater than 0 — leave blank for default 1.0 (no conversion)";
        }

        if (!empty($errors)) {
            return ['errors' => $errors];
        }
        return ['data' => $data];
    }

    /**
     * @param string $type
     * @param string $column Column key — lets us special-case bool columns
     *                        whose blank default differs from is_active.
     * @return mixed
     */
    private function emptyDefault(string $type, string $column = ''): mixed
    {
        // Range / condition columns map to NULL (= "match any") in the DB.
        // Rate components default to 0.0 so the formula is a no-op.
        return match ($type) {
            'bool'   => $this->emptyBoolDefault($column),
            'int'    => null,
            'float'  => null,
            default  => null,    // string columns also null
        };
    }

    /**
     * Default value for a `bool` column whose CSV cell is empty.
     *
     * - is_active: TRUE (merchants normally want imported rates live).
     * - delete_row (Feature 5): FALSE (blank = not a delete row — the
     *   opposite default would silently turn every row into a delete).
     * - anything else added later: FALSE by default.
     *
     * @param string $column
     * @return bool
     */
    private function emptyBoolDefault(string $column): bool
    {
        return $column === 'is_active';
    }

    /**
     * Convert + validate a CSV string into a typed value.
     *
     * @param string $raw
     * @param string $type
     * @param string $column Column name for error messages
     * @return mixed
     * @throws \InvalidArgumentException on bad input
     */
    private function castValue(string $raw, string $type, string $column): mixed
    {
        return match ($type) {
            'int'    => $this->castInt($raw, $column),
            'float'  => $this->castFloat($raw, $column),
            'bool'   => $this->castBool($raw, $column),
            'string' => $raw,
            default  => $raw,
        };
    }

    private function castInt(string $raw, string $column): int
    {
        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new \InvalidArgumentException("'{$raw}' is not a valid integer");
        }
        return (int) $raw;
    }

    private function castFloat(string $raw, string $column): float
    {
        // Accept "12.34" / "12,34" (European decimal) / "1,234.56" (thousands)
        $normalised = $raw;
        // If both "," and "." present, treat "," as thousands separator
        if (strpos($raw, ',') !== false && strpos($raw, '.') !== false) {
            $normalised = str_replace(',', '', $raw);
        } elseif (strpos($raw, ',') !== false && strpos($raw, '.') === false) {
            // Single comma — treat as decimal separator
            $normalised = str_replace(',', '.', $raw);
        }
        if (!is_numeric($normalised)) {
            throw new \InvalidArgumentException("'{$raw}' is not a valid number");
        }
        return (float) $normalised;
    }

    private function castBool(string $raw, string $column): bool
    {
        $normalised = strtolower($raw);
        return match (true) {
            in_array($normalised, ['1', 'yes', 'true', 'y', 't', 'on'],  true) => true,
            in_array($normalised, ['0', 'no', 'false', 'n', 'f', 'off'], true) => false,
            default => throw new \InvalidArgumentException("'{$raw}' is not a valid yes/no value (use 1/0 or yes/no or true/false)"),
        };
    }
}
