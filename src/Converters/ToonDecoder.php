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

    protected string $expandPaths;

    protected array $quotedKeys = [];

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
        $this->expandPaths = (string) ($config['expandPaths'] ?? 'off');
    }

    protected function getLineIndent(string $line): int
    {
        $trimmed = ltrim($line);

        if ($trimmed === '') {
            return 0;
        }

        $leadingWhitespace = substr($line, 0, strlen($line) - strlen($trimmed));
        $indentSize = strlen($leadingWhitespace);

        if ($this->strict && $indentSize > 0) {
            if (str_contains($leadingWhitespace, "\t")) {
                throw ToonException::tabInIndentation();
            }

            if ($indentSize % $this->indent !== 0) {
                throw ToonException::invalidIndentation($indentSize, $this->indent);
            }
        }

        return $indentSize;
    }

    public function decode(string $toon): mixed
    {
        $this->lines = explode("\n", $toon);
        $this->lineCount = count($this->lines);
        $this->pos = 0;
        $this->quotedKeys = [];

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

        // Apply path expansion if enabled
        if ($this->expandPaths === 'safe' && is_array($result) && ! array_is_list($result)) {
            $result = $this->expandDottedPaths($result);
        }

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

    protected function expandDottedPaths(array $obj): array
    {
        $result = [];

        foreach ($obj as $key => $value) {
            // Recursively expand nested objects first
            if (is_array($value) && ! array_is_list($value)) {
                $value = $this->expandDottedPaths($value);
            }

            // Check if key should be expanded (contains dots and all segments are valid identifiers)
            if ($this->shouldExpandKey((string) $key)) {
                $this->setNestedValue($result, (string) $key, $value);
            } else {
                $this->mergeValue($result, (string) $key, $value);
            }
        }

        return $result;
    }

    protected function shouldExpandKey(string $key): bool
    {
        // Don't expand keys that were originally quoted
        if (in_array($key, $this->quotedKeys, true)) {
            return false;
        }

        if (! str_contains($key, '.')) {
            return false;
        }

        $segments = explode('.', $key);
        foreach ($segments as $segment) {
            // Each segment must be a valid identifier (letters, numbers, underscore, starting with letter/underscore)
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                return false;
            }
        }

        return true;
    }

    protected function setNestedValue(array &$target, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $current = &$target;

        for ($i = 0; $i < count($segments) - 1; $i++) {
            $segment = $segments[$i];

            if (! isset($current[$segment])) {
                $current[$segment] = [];
            } elseif (! is_array($current[$segment])) {
                // Conflict: trying to create nested path but value exists
                if ($this->strict) {
                    throw ToonException::pathConflict($path);
                }
                // In non-strict mode, overwrite with object
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $lastSegment = $segments[count($segments) - 1];
        $this->mergeValue($current, $lastSegment, $value);
    }

    protected function mergeValue(array &$target, string $key, mixed $value): void
    {
        if (! isset($target[$key])) {
            $target[$key] = $value;

            return;
        }

        $existing = $target[$key];

        // Both are associative arrays - deep merge
        if (is_array($existing) && ! array_is_list($existing) && is_array($value) && ! array_is_list($value)) {
            foreach ($value as $k => $v) {
                $this->mergeValue($target[$key], (string) $k, $v);
            }

            return;
        }

        // Conflict: different types or primitives
        if ($this->strict) {
            throw ToonException::pathConflict($key);
        }

        // Non-strict: last write wins
        $target[$key] = $value;
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

            $indent = $this->getLineIndent($line);
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

            // Plain value - in strict mode, non-list items in nested blocks need colons
            if ($this->strict && $minIndent >= 0) {
                throw ToonException::missingSyntax('colon in key-value pair');
            }

            // In strict mode at root level, multiple bare primitives is an error
            if ($this->strict && $minIndent < 0 && ! empty($result) && array_is_list($result)) {
                $hasPrimitive = false;
                foreach ($result as $item) {
                    if (! is_array($item)) {
                        $hasPrimitive = true;
                        break;
                    }
                }
                if ($hasPrimitive) {
                    throw ToonException::invalidRootStructure();
                }
            }

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

            $indent = $this->getLineIndent($line);
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

            // In strict mode, detect delimiter mismatch
            if ($this->strict && count($columns) > 1) {
                $this->validateRowDelimiter($rowContent, $delimiter);
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

        // In strict mode, check for extra rows beyond declared count
        if ($this->strict && $this->pos < $this->lineCount) {
            $nextLine = $this->lines[$this->pos];
            $nextContent = trim($nextLine);
            if ($nextContent !== '') {
                $nextIndent = $this->getLineIndent($nextLine);
                if ($nextIndent > $baseIndent) {
                    throw ToonException::arrayLengthMismatch($rowCount, $rowCount + 1);
                }
            }
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
                if ($this->strict) {
                    throw ToonException::blankLineInArrayBlock();
                }
                $this->pos++;

                continue;
            }

            $indent = $this->getLineIndent($line);
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

        // In strict mode, check for extra list items beyond declared count
        if ($this->strict && $this->pos < $this->lineCount) {
            $nextLine = $this->lines[$this->pos];
            $nextContent = trim($nextLine);
            if ($nextContent !== '' && (str_starts_with($nextContent, '- ') || $nextContent === '-')) {
                $nextIndent = $this->getLineIndent($nextLine);
                if ($nextIndent >= $baseIndent || $baseIndent < 0) {
                    throw ToonException::arrayLengthMismatch($expectedCount, $expectedCount + 1);
                }
            }
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

    protected function validateRowDelimiter(string $row, string $expectedDelimiter): void
    {
        $otherDelimiters = [',', "\t", '|'];
        $hasExpected = $this->containsUnquotedDelimiter($row, $expectedDelimiter);

        foreach ($otherDelimiters as $other) {
            if ($other === $expectedDelimiter) {
                continue;
            }

            if ($this->containsUnquotedDelimiter($row, $other)) {
                if (! $hasExpected) {
                    $expectedName = $expectedDelimiter === "\t" ? 'tab' : $expectedDelimiter;
                    $actualName = $other === "\t" ? 'tab' : $other;
                    throw ToonException::delimiterMismatch($expectedName, $actualName);
                }
            }
        }
    }

    protected function containsUnquotedDelimiter(string $content, string $delimiter): bool
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

            if (! $inQuotes && $char === $delimiter) {
                return true;
            }
        }

        return false;
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

        if ($inQuotes) {
            throw ToonException::unterminatedString();
        }

        $cells[] = $this->parseValue($current);

        if ($expectedCount > 0) {
            if ($this->strict && count($cells) !== $expectedCount) {
                throw ToonException::arrayLengthMismatch($expectedCount, count($cells));
            }

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
            $unquoted = $this->unescapeString(substr($key, 1, -1));
            $this->quotedKeys[] = $unquoted;

            return $unquoted;
        }

        return $key;
    }

    protected function parseValue(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '"')) {
            if (! str_ends_with($trimmed, '"') || strlen($trimmed) < 2) {
                throw ToonException::unterminatedString();
            }

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
