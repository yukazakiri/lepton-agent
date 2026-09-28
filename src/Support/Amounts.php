<?php

declare(strict_types=1);

namespace Yukazakiri\Lepton\Support;

use InvalidArgumentException;

final class Amounts
{
    /**
     * Convert decimal string like "100.50" to base units (100500000 for 6 decimals).
     * Never accepts floats to avoid precision drift.
     */
    public static function fromDecimalString(string $decimal, int $decimals = 6): int
    {
        $decimal = trim($decimal);

        if (! preg_match('/^\d+(\.\d{1,'.$decimals.'})?$/', $decimal)) {
            throw new InvalidArgumentException("Invalid decimal amount [{$decimal}].");
        }

        $parts = explode('.', $decimal);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';
        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        return ((int) $whole) * (10 ** $decimals) + (int) $fraction;
    }

    public static function toDecimalString(int $baseUnits, int $decimals = 6): string
    {
        $divisor = 10 ** $decimals;
        $whole = intdiv($baseUnits, $divisor);
        $fraction = str_pad((string) ($baseUnits % $divisor), $decimals, '0', STR_PAD_LEFT);

        return $whole.'.'.rtrim($fraction, '0') === $whole.'.' ? $whole.'.0' : $whole.'.'.rtrim($fraction, '0');
    }

    /**
     * Format for `circle` CLI which expects decimal like "100.5", not base units.
     */
    public static function toCliAmount(int $baseUnits, int $decimals = 6): string
    {
        $divisor = 10 ** $decimals;
        $whole = intdiv($baseUnits, $divisor);
        $remainder = $baseUnits % $divisor;

        if ($remainder === 0) {
            return (string) $whole;
        }

        $fraction = rtrim(str_pad((string) $remainder, $decimals, '0', STR_PAD_LEFT), '0');

        return $whole.'.'.$fraction;
    }

    /**
     * Convert a JSON-RPC hex quantity to an exact decimal string.
     *
     * Uses string arithmetic so values above PHP_INT_MAX stay precise. Arc
     * native USDC is 18 decimals, so 20 USDC is 2e19 wei, which overflows a
     * 64-bit integer and silently corrupts any hexdec() cast.
     */
    public static function fromHexQuantity(string $hex, int $decimals = 18): string
    {
        $hex = strtolower(trim($hex));

        if ($hex === '' || ! preg_match('/^0x[0-9a-f]+$/', $hex)) {
            throw new InvalidArgumentException("Invalid hex quantity [{$hex}].");
        }

        $digits = substr($hex, 2);
        $decimal = '0';

        foreach (str_split($digits) as $digit) {
            // decimal = decimal * 16 + digit, all in string space.
            $carry = (int) hexdec($digit);
            $out = '';

            for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
                $value = ((int) $decimal[$i]) * 16 + $carry;
                $out = (string) ($value % 10).$out;
                $carry = intdiv($value, 10);
            }

            while ($carry > 0) {
                $out = (string) ($carry % 10).$out;
                $carry = intdiv($carry, 10);
            }

            $decimal = ltrim($out, '0');

            if ($decimal === '') {
                $decimal = '0';
            }
        }

        return self::placeDecimalPoint($decimal, $decimals);
    }

    /**
     * Insert a decimal point $decimals from the right of a digit string.
     */
    public static function placeDecimalPoint(string $digits, int $decimals): string
    {
        if ($decimals <= 0) {
            return ltrim($digits, '0') ?: '0';
        }

        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return '0.'.str_repeat('0', $decimals - 1).'0';
        }

        if (strlen($digits) <= $decimals) {
            $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);
        }

        $whole = substr($digits, 0, -$decimals);
        $fraction = rtrim(substr($digits, -$decimals), '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
