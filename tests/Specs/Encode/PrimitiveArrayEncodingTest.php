<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handels inline arrays of strings, numbers, booleans',
    function (
        mixed $input,
        mixed $expected,
        array $options = [],
        bool $shouldError = false,
    ) {
        expect(Toon::encode($input))
            ->when(
                $shouldError,
                fn ($e) => $e->toThrow(\Exception::class)
            )
            ->toEqual($expected);
    }
)
    ->with(
        array_map(
            fn (array $s) => [
                'input' => $s['input'],
                'expected' => $s['expected'] ?? null,
                'options' => $s['options'] ?? [],
                'shouldError' => $s['shouldError'] ?? false,
            ],
            (function (): array {
                try {
                    if (! file_exists($file = __DIR__.'/../../../node_modules/@toon-format/spec/tests/fixtures/encode/arrays-primitive.json')) {
                        throw new \Exception("Spec file [{$file}] not found");
                    }

                    return array_column(
                        json_decode(
                            file_get_contents($file),
                            true,
                            flags: JSON_THROW_ON_ERROR,
                        )['tests'],
                        null,
                        'name'
                    );
                } catch (\Exception $e) {
                    return [
                        $e->getMessage() => [
                            'input' => '',
                            'expected' => false,
                        ],
                    ];
                }
            })()
        )
    )
    ->group('spec', 'encode');
