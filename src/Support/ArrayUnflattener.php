<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Support;

class ArrayUnflattener
{
    /**
     * @param  array<array<mixed>>  $rows
     * @param  array<string>  $columns
     * @return array<array<string, mixed>>
     */
    public function unflatten(array $rows, array $columns): array
    {
        return array_map(fn (array $row) => $this->unflattenRow($row, $columns), $rows);
    }

    /**
     * @param  array<mixed>  $row
     * @param  array<string>  $columns
     * @return array<string, mixed>
     */
    protected function unflattenRow(array $row, array $columns): array
    {
        $item = [];

        foreach ($columns as $i => $column) {
            $this->setByPath($item, $column, $row[$i] ?? null);
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function setByPath(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $current = &$data;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                $current[$segment] ??= [];
                $current = &$current[$segment];
            }
        }
    }
}
