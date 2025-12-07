<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it('encodes simple key-value pairs', function () {
    $data = ['name' => 'John', 'age' => 30];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: John');
    expect($toon)->toContain('age: 30');
});

it('encodes nested objects', function () {
    $data = [
        'user' => [
            'name' => 'John',
            'email' => 'john@example.com',
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('user:');
    expect($toon)->toContain('name: John');
    expect($toon)->toContain('email: john@example.com');
});

it('encodes uniform arrays as tables', function () {
    $data = [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('items[2]{id,name}:');
    expect($toon)->toContain('1,Alice');
    expect($toon)->toContain('2,Bob');
});

it('encodes booleans correctly', function () {
    $data = ['active' => true, 'deleted' => false];

    $toon = Toon::encode($data);

    expect($toon)->toContain('active: true');
    expect($toon)->toContain('deleted: false');
});

it('encodes null as empty string', function () {
    $data = [
        ['id' => 1, 'name' => null],
        ['id' => 2, 'name' => 'Bob'],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('1,');
    expect($toon)->toContain('2,Bob');
});

it('escapes special characters', function () {
    $data = ['message' => 'Hello, World: Test'];

    $toon = Toon::encode($data);

    expect($toon)->toContain('Hello\\, World\\: Test');
});

it('handles deeply nested structures', function () {
    $data = [
        'level1' => [
            'level2' => [
                'level3' => 'deep value',
            ],
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('level1:');
    expect($toon)->toContain('level2:');
    expect($toon)->toContain('level3: deep value');
});

it('reduces token count compared to json', function () {
    $data = [
        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'active' => true],
        ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'active' => false],
        ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com', 'active' => true],
    ];

    $json = json_encode($data);
    $toon = Toon::encode($data);

    expect(strlen($toon))->toBeLessThan(strlen($json));
});
