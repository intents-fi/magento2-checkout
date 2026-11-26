<?php
/**
 * Decimal amounts, without floats.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

/**
 * Invoice prices are decimal strings with two places (`"49.00"`, SPEC-checkout §4.2). Magento
 * stores totals as DECIMAL(20,4) and hands them back as strings or floats, so the conversion is
 * done on the digits, never through a float, and rounding is half-up on the third decimal.
 */
class Amount
{
    /**
     * Format a total as the API wants it.
     *
     * @param string|int|float $total
     * @throws \InvalidArgumentException When the value is not a plain non-negative decimal.
     */
    public static function format($total): string
    {
        if (is_float($total)) {
            // A float from Magento is a DECIMAL(20,4) column read back; four places is all it holds.
            $total = number_format($total, 4, '.', '');
        }
        $value = trim((string) $total);
        if (preg_match('/^([0-9]+)(?:\.([0-9]*))?$/', $value, $m) !== 1) {
            throw new \InvalidArgumentException('Not a non-negative decimal amount.');
        }

        $integer = ltrim($m[1], '0');
        $fraction = str_pad($m[2] ?? '', 3, '0');
        $cents = ($integer === '' ? '0' : $integer) . substr($fraction, 0, 2);
        $roundUp = ((int) $fraction[2]) >= 5;

        $cents = ltrim($cents, '0');
        if ($cents === '') {
            $cents = '0';
        }
        if ($roundUp) {
            $cents = self::increment($cents);
        }

        $cents = str_pad($cents, 3, '0', STR_PAD_LEFT);
        return substr($cents, 0, -2) . '.' . substr($cents, -2);
    }

    /** Are two decimal amounts the same number of cents? Malformed input never matches. */
    public static function equals(string $a, string $b): bool
    {
        try {
            return self::format($a) === self::format($b);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /** Add one to a string of digits. */
    private static function increment(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) (((int) $digits[$i]) + 1);
                return $digits;
            }
            $digits[$i] = '0';
            $i--;
        }
        return '1' . $digits;
    }
}
