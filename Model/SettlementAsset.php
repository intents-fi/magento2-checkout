<?php
/**
 * Human-readable settlement amounts for order comments.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

/**
 * The settlement assets intents.fi pays out in (SPEC-checkout §5.2, `src/checkout/settlement-assets.ts`
 * in the intents.fi repository), with their symbol, decimals and network, so an order comment can
 * say "9.96 USDC on Base" instead of "9960000 of eip155:8453/erc20:0x8335…".
 *
 * Integer string arithmetic only: no floats touch an amount. An asset that is not listed here is
 * shown in base units with its CAIP-19 id, so a new asset is never mis-scaled.
 */
class SettlementAsset
{
    /** CAIP-19 id => [symbol, decimals, network]. */
    private const ASSETS = [
        'eip155:8453/erc20:0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913' => ['USDC', 6, 'Base'],
        'eip155:42161/erc20:0xaf88d065e77c8cC2239327C5EDb3A432268e5831' => ['USDC', 6, 'Arbitrum'],
        'eip155:1/erc20:0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48' => ['USDC', 6, 'Ethereum'],
        'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp/token:EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v' => ['USDC', 6, 'Solana'],
        'eip155:137/erc20:0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359' => ['USDC', 6, 'Polygon'],
        'intents:tron/trc20:TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t' => ['USDT', 6, 'Tron'],
        'eip155:1/erc20:0xdAC17F958D2ee523a2206206994597C13D831ec7' => ['USDT', 6, 'Ethereum'],
        'eip155:42161/erc20:0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9' => ['USDT', 6, 'Arbitrum'],
        'eip155:56/erc20:0x55d398326f99059fF775485246999027B3197955' => ['USDT', 18, 'BNB Chain'],
        'bip122:000000000019d6689c085ae165831e93/slip44:0' => ['BTC', 8, 'Bitcoin'],
    ];

    /**
     * "9.96 USDC on Base". Stablecoins keep at least two decimals ("10.00"); BTC drops trailing
     * zeros. Unknown assets or malformed amounts: "<amount> base units of <asset id>".
     */
    public static function format(string $baseUnits, string $assetId): string
    {
        $known = self::ASSETS[$assetId] ?? null;
        if ($known === null || preg_match('/^\d+$/', $baseUnits) !== 1) {
            return sprintf('%s base units of %s', $baseUnits, $assetId);
        }
        [$symbol, $decimals, $network] = $known;
        return sprintf('%s %s on %s', self::decimal($baseUnits, $decimals, $symbol === 'BTC' ? 0 : 2), $symbol, $network);
    }

    /** Base units to a decimal string, keeping at least `$minFraction` fraction digits. */
    public static function decimal(string $baseUnits, int $decimals, int $minFraction): string
    {
        $digits = ltrim($baseUnits, '0');
        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, strlen($digits) - $decimals);
        $fraction = $decimals > 0 ? substr($digits, -$decimals) : '';
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) < $minFraction) {
            $fraction = str_pad($fraction, $minFraction, '0');
        }
        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }
}
