<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon;

use MischaSigtermans\Toon\Converters\ToonDecoder;
use MischaSigtermans\Toon\Converters\ToonEncoder;

class Toon
{
    public function __construct(
        protected ToonEncoder $encoder,
        protected ToonDecoder $decoder,
    ) {}

    public function encode(mixed $data): string
    {
        return $this->encoder->encode($data);
    }

    public function decode(string $toon): array
    {
        return $this->decoder->decode($toon);
    }
}
