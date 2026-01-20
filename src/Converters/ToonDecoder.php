<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Converters;

use Illuminate\Support\Facades\Config;
use MischaSigtermans\Toon\Exceptions\ToonException;
use MischaSigtermans\Toon\Support\ArrayUnflattener;

class ToonDecoder
{
    protected ArrayUnflattener $unflattener;

    protected int $indent;

    protected bool $strict;

    protected string $delimiter;

    protected array $lines;

    protected int $lineCount;

    protected int $pos;

    public function __construct(?ArrayUnflattener $unflattener = null, ?array $config = null)
    {
        $config ??= Config::get('toon', []);

        $this->unflattener = $unflattener ?? new ArrayUnflattener;
        $this->indent = (int) ($config['indent'] ?? 2);
        $this->strict = (bool) ($config['strict'] ?? true);
        $this->delimiter = (string) ($config['delimiter'] ?? ',');
    }

    public function decode(string $toon): mixed
    {
        $this->lines = explode("\n", $toon);
        $this->lineCount = count($this->lines);
        $this->pos = 0;

        // Empty input returns empty object
        $hasContent = false;
        foreach ($this->lines as $line) {
            if (trim($line) !== '') {
                $hasContent = true;
                break;
            }
        }

        if (! $hasContent) {
            return [];
        }

        $result = $this->parseBlock(-1);

        // If the result is an array with sequential numeric keys and contains
        // only one element that is NOT an associative array, return that element
        // This handles single primitive values at root level
        if (array_is_list($result) && count($result) === 1) {
            $first = $result[0];
            // Only unwrap if it's a primitive (not an array/object)
            if (! is_array($first)) {
                return $first;
            }
        }

        return $result;
    }

    protected function parseBlock(int $minIndent): array
    {
        $result = [];

        while ($this->pos < $this->lineCount) {
            $line = $this->lines[$this->pos];

            if (trim($line) === '') {
                $this->pos++;

                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));
            $content = trim($line);

            if ($indent <= $minIndent && $minIndent >= 0) {
                break;
            }

            // Check for list item
            if (str_starts_with($content, '- ') || $content === '-') {
                $itemContent = $content === '-' ? '' : substr($content, 2);
                $this->pos++;
                $item = $this->parseListItem($itemContent, $indent);
                $result[] = $item;

                continue;
            }

            // Check for array header
            $arrayMatch = $this->parseArrayHeader($content);
            if ($arrayMatch !== null) {
                $keyName = $arrayMatch['key'];
                $rowCount = $arrayMatch['count'];
                $columns = $arrayMatch['columns'];
                $activeDelimiter = $arrayMatch['delimiter'];
                $inlineValues = $arrayMatch['inline'];

                $this->pos++;

                if ($rowCount === 0) {
                    // Empty array
                    $items = [];
                } elseif ($inlineValues !== '' && $columns === []) {
                    // Inline primitive array: key[N]: a,b,c
                    $items = $this->parseRow($inlineValues, $rowCount, $activeDelimiter);
                } elseif ($columns !== []) {
                    // Tabular array with columns
                    $items = $this->parseTabularRows($rowCount, $columns, $activeDelimiter, $indent);
                } else {
                    // List format array or simple rows
                    $items = $this->parseListOrRows($rowCount, $indent, $activeDelimiter);
                }

                if ($keyName !== null) {
                    $result[$keyName] = $items;
                } else {
                    foreach ($items as $item) {
                        $result[] = $item;
                    }
                }

                continue;
            }

            // Check for nested object (key:)
            if (str_ends_with($content, ':') && ! $this->containsKeyValueSeparator($content)) {
                $key = $this->parseKey(rtrim($content, ':'));
                $this->pos++;
                $result[$key] = $this->parseBlock($indent);

                continue;
            }

            // Check for key-value pair
            if ($this->containsKeyValueSeparator($content)) {
                [$key, $value] = $this->splitKeyValue($content);
                $result[$this->parseKey($key)] = $this->parseValue($value);
                $this->pos++;

                continue;
            }

            // Plain value
            $result[] = $this->parseValue($content);
            $this->pos++;
        }

        return $result;
    }

    protected function parseListItem(string $content, int $listIndent): mixed
    {
        // Empty list item becomes empty object
        if ($content === '') {
            return [];
        }

        // Check if it's an inline array: [N]: values or [N]{cols}: values
        $arrayMatch = $this->parseArrayHeader($content);
        if ($arrayMatch !== null) {
            $rowCount = $arrayMatch['count'];
            $columns = $arrayMatch['columns'];
            $activeDelimiter = $arrayMatch['delimiter'];
            $inlineValues = $arrayMatch['inline'];
            $keyName = $arrayMatch['key'];

            if ($rowCount === 0) {
                $items = [];
            } elseif ($inlineValues !== '' && $columns === []) {
                $items = $this->parseRow($inlineValues, $rowCount, $activeDelimiter);
            } elseif ($columns !== []) {
                $items = $this->parseTabularRows($rowCount, $columns, $activeDelimiter, $listIndent + 2);
            } else {
                $items = $this->parseListOrRows($rowCount, $listIndent + 2, $activeDelimiter);
            }

            if ($keyName !== null) {
                // It's an object with an array field, parse more fields
                $obj = [$keyName => $items];
                $this->parseObjectFields($obj, $listIndent);

                return $obj;
            }

            return $items;
        }

        // Check if it's a key-value pair (start of an object)
        if ($this->containsKeyValueSeparator($content)) {
            [$key, $value] = $this->splitKeyValue($content);
            $obj = [$this->parseKey($key) => $this->parseValue($value)];
            $this->parseObjectFields($obj, $listIndent);

            return $obj;
        }

        // Check for nested object key (key:) - containsKeyValueSeparator already returned above
        if (str_ends_with($content, ':')) {
            $key = $this->parseKey(rtrim($content, ':'));
            $obj = [$key => $this->parseBlock($listIndent + 2)];
            $this->parseObjectFields($obj, $listIndent);

            return $obj;
        }

        // It's a primitive value
        return $this->parseValue($content);
    }

    protected function parseObjectFields(array &$obj, int $listIndent): void
    {
        while ($this->pos < $this->lineCount) {
            $line = $this->lines[$this->pos];

            if (trim($line) === '') {
                $this->pos++;

                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));
            $content = trim($line);

            // Stop if we've dedented or hit another list item
            if ($indent <= $listIndent || str_starts_with($content, '- ') || $content === '-') {
                break;
            }

            // Check for array header within object
            $arrayMatch = $this->parseArrayHeader($content);
            if ($arrayMatch !== null) {
                $keyName = $arrayMatch['key'];
                $rowCount = $arrayMatch['count'];
                $columns = $arrayMatch['columns'];
                $activeDelimiter = $arrayMatch['delimiter'];
                $inlineValues = $arrayMatch['inline'];

                $this->pos++;

                if ($rowCount === 0) {
                    $items = [];
                } elseif ($inlineValues !== '' && $columns === []) {
                    $items = $this->parseRow($inlineValues, $rowCount, $activeDelimiter);
                } elseif ($columns !== []) {
                    $items = $this->parseTabularRows($rowCount, $columns, $activeDelimiter, $indent);
                } else {
                    $items = $this->parseListOrRows($rowCount, $indent, $activeDelimiter);
                }

                if ($keyName !== null) {
                    $obj[$keyName] = $items;
                }

                continue;
            }

            // Check for nested object (key:)
            if (str_ends_with($content, ':') && ! $this->containsKeyValueSeparator($content)) {
                $key = $this->parseKey(rtrim($content, ':'));
                $this->pos++;
                $obj[$key] = $this->parseBlock($indent);

                continue;
            }

            // Key-value pair
            if ($this->containsKeyValueSeparator($content)) {
                [$key, $value] = $this->splitKeyValue($content);
                $obj[$this->parseKey($key)] = $this->parseValue($value);
                $this->pos++;

                continue;
            }

            break;
        }
    }

    protected function parseTabularRows(int $rowCount, array $columns, string $delimiter, int $baseIndent): array
    {
        $rows = [];

        for ($j = 0; $j < $rowCount && $this->pos < $this->lineCount; $j++) {
            $rowLine = $this->lines[$this->pos];
            $rowContent = trim($rowLine);

            if ($rowContent === '') {
                if ($this->strict) {
                    throw ToonException::blankLineInArrayBlock();
                }
                $this->pos++;
                $j--;

                continue;
            }

            $cells = $this->parseRow($rowContent, count($columns), $delimiter);

            if ($this->strict && count($cells) !== count($columns)) {
                throw ToonException::rowWidthMismatch(count($columns), count($cells));
            }

            $rows[] = $cells;
            $this->pos++;
        }

        if ($this->strict && count($rows) !== $rowCount) {
            throw ToonException::arrayLengthMismatch($rowCount, count($rows));
        }

        return $this->hasNestedColumns($columns)
            ? $this->unflattener->unflatten($rows, $columns)
            : $this->rowsToObjects($rows, $columns);
    }

    protected function parseListOrRows(int $rowCount, int $baseIndent, string $delimiter): array
    {
        // Peek to see if this is list format
        if ($this->pos < $this->lineCount) {
            $peekLine = $this->lines[$this->pos];
            $peekContent = trim($peekLine);

            if (str_starts_with($peekContent, '- ') || $peekContent === '-') {
                // List format
                return $this->parseListItems($rowCount, $baseIndent);
            }
        }

        // Simple rows (arrays of single values)
        $rows = [];
        for ($j = 0; $j < $rowCount && $this->pos < $this->lineCount; $j++) {
            $rowLine = $this->lines[$this->pos];
            $rowContent = trim($rowLine);

            if ($rowContent === '') {
                if ($this->strict) {
                    throw ToonException::blankLineInArrayBlock();
                }
                $this->pos++;
                $j--;

                continue;
            }

            $rows[] = $this->parseRow($rowContent, -1, $delimiter);
            $this->pos++;
        }

        // If each row has exactly one cell, flatten
        $allSingle = true;
        foreach ($rows as $row) {
            if (count($row) !== 1) {
                $allSingle = false;
                break;
            }
        }

        if ($allSingle) {
            return array_map(fn ($r) => $r[0], $rows);
        }

        return $rows;
    }

    protected function parseListItems(int $expectedCount, int $baseIndent): array
    {
        $items = [];

        while ($this->pos < $this->lineCount && count($items) < $expectedCount) {
            $line = $this->lines[$this->pos];

            if (trim($line) === '') {
                $this->pos++;

                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));
            $content = trim($line);

            // List items should be at baseIndent or deeper
            if ($indent < $baseIndent && $baseIndent >= 0) {
                break;
            }

            if (str_starts_with($content, '- ') || $content === '-') {
                $itemContent = $content === '-' ? '' : substr($content, 2);
                $this->pos++;
                $items[] = $this->parseListItem($itemContent, $indent);
            } else {
                break;
            }
        }

        if ($this->strict && count($items) !== $expectedCount) {
            throw ToonException::arrayLengthMismatch($expectedCount, count($items));
        }

        return $items;
    }

    protected function parseArrayHeader(string $content): ?array
    {
        if (! preg_match('/^([a-zA-Z_][a-zA-Z0-9_.]*|\".+\")?\[(\d+)([\t|])?\](?:\{([^\}]*)\})?:\s*(.*)$/', $content, $m)) {
            return null;
        }

        $keyName = $m[1] !== '' ? $this->parseKey($m[1]) : null;
        $count = (int) $m[2];
        $delimiterMarker = $m[3];
        $fieldsStr = $m[4];
        $inlineValues = $m[5];

        $delimiter = match ($delimiterMarker) {
            "\t" => "\t",
            '|' => '|',
            default => ',',
        };

        $columns = [];
        if ($fieldsStr !== '') {
            $columns = $this->parseRow($fieldsStr, -1, $delimiter);
            $columns = array_map(fn ($c) => is_string($c) ? $c : (string) $c, $columns);
        }

        return [
            'key' => $keyName,
            'count' => $count,
            'columns' => $columns,
            'delimiter' => $delimiter,
            'inline' => trim($inlineValues),
        ];
    }

    protected function containsKeyValueSeparator(string $content): bool
    {
        if (! str_contains($content, ': ')) {
            return false;
        }

        $inQuotes = false;
        $escape = false;
        $len = strlen($content);

        for ($i = 0; $i < $len; $i++) {
            $char = $content[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $char === ':' && isset($content[$i + 1]) && $content[$i + 1] === ' ') {
                return true;
            }
        }

        return false;
    }

    protected function splitKeyValue(string $content): array
    {
        $inQuotes = false;
        $escape = false;
        $len = strlen($content);

        for ($i = 0; $i < $len; $i++) {
            $char = $content[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $char === ':' && isset($content[$i + 1]) && $content[$i + 1] === ' ') {
                return [
                    substr($content, 0, $i),
                    substr($content, $i + 2),
                ];
            }
        }

        return [$content, ''];
    }

    protected function parseRow(string $row, int $expectedCount, string $delimiter = ','): array
    {
        $cells = [];
        $current = '';
        $inQuotes = false;
        $escape = false;
        $len = strlen($row);

        for ($i = 0; $i < $len; $i++) {
            $char = $row[$i];

            if ($escape) {
                // Preserve the backslash for parseValue to handle escape sequences
                $current .= '\\'.$char;
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inQuotes = ! $inQuotes;
                $current .= $char;

                continue;
            }

            if (! $inQuotes && $char === $delimiter) {
                $cells[] = $this->parseValue($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $cells[] = $this->parseValue($current);

        if ($expectedCount > 0) {
            while (count($cells) < $expectedCount) {
                $cells[] = null;
            }
        }

        return $cells;
    }

    protected function parseKey(string $key): string
    {
        $key = trim($key);

        if (str_starts_with($key, '"') && str_ends_with($key, '"')) {
            return $this->unescapeString(substr($key, 1, -1));
        }

        return $key;
    }

    protected function parseValue(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"')) {
            return $this->unescapeString(substr($trimmed, 1, -1));
        }

        $value = $trimmed;

        if ($value === 'null') {
            return null;
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if ($this->isNumeric($value)) {
            // Numbers with decimal points or exponents should be floats
            if (str_contains($value, '.') || stripos($value, 'e') !== false) {
                return (float) $value;
            }

            return (int) $value;
        }

        return $this->unescapeLegacy($value);
    }

    protected function isNumeric(string $value): bool
    {
        if (preg_match('/^0\d+$/', $value)) {
            return false;
        }

        return (bool) preg_match('/^-?\d+(?:\.\d+)?(?:e[+-]?\d+)?$/i', $value);
    }

    protected function unescapeString(string $s): string
    {
        if (! str_contains($s, '\\')) {
            return $s;
        }

        $result = '';
        $escape = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $char = $s[$i];

            if ($escape) {
                $result .= match ($char) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    default => $this->strict
                        ? throw ToonException::invalidEscapeSequence($char)
                        : $char,
                };
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    protected function unescapeLegacy(string $value): string
    {
        $value = str_replace('\\n', "\n", $value);
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\:', ':', $value);
        $value = str_replace('\\\\', '\\', $value);

        return $value;
    }

    protected function hasNestedColumns(array $columns): bool
    {
        foreach ($columns as $col) {
            if (str_contains($col, '.')) {
                return true;
            }
        }

        return false;
    }

    protected function rowsToObjects(array $rows, array $columns): array
    {
        return array_map(
            fn (array $row) => array_combine($columns, $row) ?: [],
            $rows
        );
    }
}
