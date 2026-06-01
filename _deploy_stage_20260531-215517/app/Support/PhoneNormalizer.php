<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class PhoneNormalizer
{
    public static function normalizeBrazilian(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        if ($digits === '') {
            throw new InvalidArgumentException('Telefone invalido');
        }

        if (str_starts_with($digits, '00')) {
            throw new InvalidArgumentException('Telefone invalido');
        }

        if (str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (!preg_match('/^[1-9][0-9]{1}[0-9]{8,9}$/', $digits)) {
            throw new InvalidArgumentException('Telefone brasileiro invalido');
        }

        return '55' . $digits;
    }
}
