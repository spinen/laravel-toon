<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Facades;

use Illuminate\Support\Facades\Facade;

class Toon extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \MischaSigtermans\Toon\Toon::class;
    }
}
