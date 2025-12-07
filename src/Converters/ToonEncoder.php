<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Converters;

use Illuminate\Support\Facades\Config;
use MischaSigtermans\Toon\Support\ArrayFlattener;

class ToonEncoder
{
    protected ArrayFlattener $flattener;

    protected int $minRowsForTable;

    protected string $escapeStyle;

    public function __construct(?ArrayFlattener $flattener = null)
    {
        $maxDepth = (int) Config::get('toon.max_flatten_depth', 3);
        $this->flattener = $flattener ?? new ArrayFlattener($maxDepth);
        $this->minRowsForTable = (int) Config::get('toon.min_rows_for_table', 2);
        $this->escapeStyle = (string) Config::get('toon.escape_style', 'backslash');
    }

    public function encode(mixed $input): string
    {
        if (is_string($input) && $this->looksLikeJson($input)) {
            $decoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->valueToToon($decoded);
            }
        }

        if (is_object($input)) {
            $input = json_decode(json_encode($input), true);
        }

        if (is_array($input)) {
            return $this->valueToToon($input);
        }

        return $this->escapeScalar($input);
    }

    protected function valueToToon(mixed $value, int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);

        if (is_array($value)) {
            if ($this->isSequentialArray($value)) {
                if ($this->flattener->hasNestedObjects($value)) {
                    $flattened = $this->flattener->flatten($value);

                    return $this->flattenedToToon($flattened, $depth);
                }

                if ($this->isArrayOfUniformObjects($value)) {
                    return $this->arrayOfObjectsToToon($value, $depth);
                }

                return $this->sequentialArrayToToon($value, $depth);
            }

            return $this->associativeArrayToToon($value, $depth);
        }

        return $indent.$this->escapeScalar($value);
    }

    /**
     * @param  array{columns: array<string>, rows: array<array<mixed>>}  $flattened
     */
    protected function flattenedToToon(array $flattened, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $columns = $flattened['columns'];
        $rows = $flattened['rows'];

        $header = $indent.'items['.count($rows).']{'.implode(',', $columns).'}:';

        $rowLines = array_map(
            fn (array $row) => $indent.'  '.implode(',', array_map([$this, 'escapeScalar'], $row)),
            $rows
        );

        return $header."\n".implode("\n", $rowLines);
    }

    protected function arrayOfObjectsToToon(array $arr, int $depth): string
    {
        if (empty($arr)) {
            return str_repeat('  ', $depth).'items[0]{}:';
        }

        $fields = array_keys((array) $arr[0]);
        $indent = str_repeat('  ', $depth);

        $header = $indent.'items['.count($arr).']{'.implode(',', $fields).'}:';

        $rows = [];
        foreach ($arr as $item) {
            $cells = array_map(fn ($f) => $this->escapeScalar($item[$f] ?? null), $fields);
            $rows[] = $indent.'  '.implode(',', $cells);
        }

        return $header."\n".implode("\n", $rows);
    }

    protected function sequentialArrayToToon(array $arr, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $lines = [];

        foreach ($arr as $item) {
            if ($this->isScalar($item)) {
                $lines[] = $indent.$this->escapeScalar($item);
            } else {
                $lines[] = $this->valueToToon($item, $depth);
            }
        }

        return implode("\n", $lines);
    }

    protected function associativeArrayToToon(array $arr, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $lines = [];

        foreach ($arr as $key => $val) {
            $safeKey = $this->safeKey((string) $key);

            if ($this->isScalar($val)) {
                $lines[] = $indent.$safeKey.': '.$this->escapeScalar($val);
            } else {
                $lines[] = $indent.$safeKey.':';
                $lines[] = $this->valueToToon($val, $depth + 1);
            }
        }

        return implode("\n", $lines);
    }

    protected function escapeScalar(mixed $v): string
    {
        if ($v === null) {
            return '';
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        if (is_array($v)) {
            return json_encode($v) ?: '[]';
        }

        $s = trim(preg_replace('/\s+/', ' ', (string) $v) ?? '');

        if ($this->escapeStyle === 'backslash') {
            $s = str_replace('\\', '\\\\', $s);
            $s = str_replace(',', '\\,', $s);
            $s = str_replace(':', '\\:', $s);
            $s = str_replace("\n", '\\n', $s);
        }

        return $s;
    }

    protected function safeKey(string $k): string
    {
        return preg_replace('/[^A-Za-z0-9_\-\.]/', '', $k) ?? $k;
    }

    protected function isScalar(mixed $v): bool
    {
        return is_null($v) || is_scalar($v);
    }

    protected function looksLikeJson(string $s): bool
    {
        $s = trim($s);

        return $s !== '' && (str_starts_with($s, '{') || str_starts_with($s, '['));
    }

    protected function isSequentialArray(array $arr): bool
    {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }

    protected function isArrayOfUniformObjects(array $arr): bool
    {
        if (count($arr) < $this->minRowsForTable) {
            return false;
        }

        $firstKeys = null;

        foreach ($arr as $item) {
            if (! is_array($item)) {
                return false;
            }

            $keys = array_keys($item);

            if ($firstKeys === null) {
                $firstKeys = $keys;
            } elseif ($keys !== $firstKeys) {
                return false;
            }
        }

        return true;
    }
}
