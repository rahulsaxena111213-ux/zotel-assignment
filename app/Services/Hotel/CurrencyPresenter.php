<?php

namespace App\Services\Hotel;

/**
 * Resolves ISO currency code + symbol from config (no external ICU dependency).
 */
final class CurrencyPresenter
{
    /**
     * @return array{iso_code: string, symbol: string, minor_unit_decimals: int}
     */
    public static function forApp(): array
    {
        $code = strtoupper((string) config('hotel.default_currency', 'USD'));
        $definitions = config('hotel.currency_definitions', []);

        $row = $definitions[$code] ?? ['symbol' => $code, 'decimals' => 2];

        return [
            'iso_code' => $code,
            'symbol' => (string) ($row['symbol'] ?? $code),
            'minor_unit_decimals' => (int) ($row['decimals'] ?? 2),
        ];
    }
}
