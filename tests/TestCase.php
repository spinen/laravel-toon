<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Tests;

use MischaSigtermans\Toon\Facades\Toon;
use MischaSigtermans\Toon\ToonServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ToonServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Toon' => Toon::class,
        ];
    }
}
