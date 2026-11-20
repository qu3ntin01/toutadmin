<?php

declare(strict_types=1);

namespace App\Core;

/** Une chaîne d'octets CBOR : à ne pas confondre avec une chaîne de texte. */
final class CborOctets
{
    public function __construct(public readonly string $octets)
    {
    }
}
