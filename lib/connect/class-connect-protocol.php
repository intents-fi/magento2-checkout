<?php
/**
 * intents.fi Connect, the plugin half (specs/BRIEF-merchant-onboarding.md Part D).
 *
 * One protocol for every store plugin. This file is the canonical copy: each plugin ships its own
 * copy (WooCommerce: `includes/connect/class-connect-protocol.php`), kept byte-identical by a test
 * in that plugin, so WHMCS, PrestaShop and the rest get the same checks as they adopt Connect.
 *
 * No platform code here (no WordPress, no WHMCS): it builds the authorize URL, checks what comes
 * back to `return_url`, and checks what the token exchange returns. The platform glue stores the
 * pending request, makes the HTTP call and saves the keys.
 *
 * The flow:
 *   1. {@see Connect_Protocol::begin()} makes a `state` and a PKCE `code_verifier`. The plugin keeps
 *      both server-side (never in a cookie or the page) for this admin, and opens
 *      {@see Connect_Protocol::authorize_url()}.
 *   2. The merchant approves on dashboard.intents.fi and is sent back to `return_url` with `code`
 *      and `state`. {@see Connect_Protocol::check_return()} insists on the exact `state` of a
 *      pending request that is still fresh, and only then lets the code through.
 *   3. The plugin's server POSTs {@see Connect_Protocol::token_body()} to `/v0/connect/token`, and
 *      {@see Connect_Protocol::check_token()} checks the keys are the mode that was asked for.
 *
 * @package IntentsFi\Connect
 */

declare( strict_types=1 );

namespace IntentsFi\Connect;

/**
 * The plugin side of intents.fi Connect.
 */
class Connect_Protocol {

	/**
	 * Where merchants approve a connection.
	 */
	public const DASHBOARD_URL = 'https://dashboard.intents.fi';

	/**
	 * How long a pending request may wait for the merchant, in seconds. The API's code lives five
	 * minutes; this covers signing up first.
	 */
	public const PENDING_TTL = 1800;

	/**
	 * A fresh request: `state` and `code_verifier` (32 random bytes each, base64url), the
	 * `code_challenge` that goes in the URL, the mode, and when it was made.
	 *
	 * @param string $mode `test` or `live`.
	 * @param int    $now  Unix time.
	 * @return array{state: string, verifier: string, challenge: string, mode: string, created: int}
	 */
	public static function begin( string $mode, int $now ): array {
		$verifier = self::random_token();
		return array(
			'state'     => self::random_token(),
			'verifier'  => $verifier,
			'challenge' => self::challenge( $verifier ),
			'mode'      => 'live' === $mode ? 'live' : 'test',
			'created'   => $now,
		);
	}

	/**
	 * 32 random bytes, base64url without padding (43 characters).
	 */
	public static function random_token(): string {
		return self::base64url( random_bytes( 32 ) );
	}

	/**
	 * RFC 7636 S256: base64url(SHA-256(verifier)).
	 *
	 * @param string $verifier The code verifier.
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Raw bytes.
	 */
	public static function base64url( string $bytes ): string {
		// base64url for PKCE (RFC 7636) and random state values, not obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * `scheme://host[:port]` of a URL, lower-cased, or null when it isn't an absolute http(s) URL.
	 *
	 * @param string $url A URL.
	 */
	public static function origin( string $url ): ?string {
		// No WordPress here, so PHP's own parser (wp_parse_url() is a wrapper around it).
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return null;
		}
		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$default = 'https' === $scheme ? 443 : 80;
		$origin  = $scheme . '://' . strtolower( $parts['host'] );
		if ( null !== $port && $port !== $default ) {
			$origin .= ':' . $port;
		}
		return $origin;
	}

	/**
	 * Do two URLs share an origin? The API refuses a `return_url` that doesn't share `site_url`'s,
	 * so a plugin checks first and says why instead of sending the merchant to an error.
	 *
	 * @param string $a A URL.
	 * @param string $b Another URL.
	 */
	public static function same_origin( string $a, string $b ): bool {
		$x = self::origin( $a );
		return null !== $x && self::origin( $b ) === $x;
	}

	/**
	 * The dashboard URL that starts the round trip.
	 *
	 * @param string                                                 $dashboard  Dashboard origin.
	 * @param array{state: string, challenge: string, mode: string} $pending    From {@see self::begin()}.
	 * @param string                                                 $platform   `woocommerce`, `whmcs`…
	 * @param string                                                 $site_url   The store's address.
	 * @param string                                                 $return_url Where the merchant comes back to.
	 */
	public static function authorize_url( string $dashboard, array $pending, string $platform, string $site_url, string $return_url ): string {
		$query = array(
			'platform'              => $platform,
			'site_url'              => $site_url,
			'return_url'            => $return_url,
			'state'                 => $pending['state'],
			'code_challenge'        => $pending['challenge'],
			'code_challenge_method' => 'S256',
			'mode'                  => $pending['mode'],
		);
		return rtrim( $dashboard, '/' ) . '/connect?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Check what came back to `return_url`.
	 *
	 * Returns `['ok' => true, 'code' => …]`, or `['ok' => false, 'error' => …]` where the error is one
	 * of `no_request` (nothing pending for this admin), `expired`, `state` (doesn't match: possibly
	 * forged), `cancelled` (the merchant pressed Cancel) or `bad_code`.
	 *
	 * @param array<string, mixed>                        $query   The query string, already unslashed.
	 * @param array<string, mixed>|null                   $pending The request this admin started, if any.
	 * @param int                                         $now     Unix time.
	 * @return array{ok: bool, code?: string, error?: string}
	 */
	public static function check_return( array $query, ?array $pending, int $now ): array {
		if ( null === $pending || ! isset( $pending['state'], $pending['verifier'], $pending['created'] ) ) {
			return array(
				'ok'    => false,
				'error' => 'no_request',
			);
		}
		if ( $now - (int) $pending['created'] > self::PENDING_TTL ) {
			return array(
				'ok'    => false,
				'error' => 'expired',
			);
		}
		$state = isset( $query['state'] ) && is_string( $query['state'] ) ? $query['state'] : '';
		if ( '' === $state || ! hash_equals( (string) $pending['state'], $state ) ) {
			return array(
				'ok'    => false,
				'error' => 'state',
			);
		}
		if ( isset( $query['error'] ) ) {
			return array(
				'ok'    => false,
				'error' => 'cancelled',
			);
		}
		$code = isset( $query['code'] ) && is_string( $query['code'] ) ? $query['code'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{20,256}$/', $code ) ) {
			return array(
				'ok'    => false,
				'error' => 'bad_code',
			);
		}
		return array(
			'ok'   => true,
			'code' => $code,
		);
	}

	/**
	 * The body of `POST /v0/connect/token`.
	 *
	 * @param string               $code     The one-time code.
	 * @param array<string, mixed> $pending  The request it answers.
	 * @param string               $site_url The same `site_url` the request named.
	 * @return array{code: string, code_verifier: string, site_url: string}
	 */
	public static function token_body( string $code, array $pending, string $site_url ): array {
		return array(
			'code'          => $code,
			'code_verifier' => (string) $pending['verifier'],
			'site_url'      => $site_url,
		);
	}

	/**
	 * Check a token response: the keys are well formed and in the mode that was asked for.
	 * Returns the fields a plugin stores, or null when the response can't be trusted.
	 *
	 * @param mixed  $response Decoded JSON.
	 * @param string $mode     The mode the request asked for.
	 * @return array{secret_key: string, publishable_key: string, mode: string, business: string, merchant_status: string, connection_id: string, site: string}|null
	 */
	public static function check_token( $response, string $mode ): ?array {
		if ( ! is_array( $response ) ) {
			return null;
		}
		$secret      = isset( $response['secret_key'] ) && is_string( $response['secret_key'] ) ? $response['secret_key'] : '';
		$publishable = isset( $response['publishable_key'] ) && is_string( $response['publishable_key'] ) ? $response['publishable_key'] : '';
		$got_mode    = isset( $response['mode'] ) && is_string( $response['mode'] ) ? $response['mode'] : '';
		if ( $got_mode !== $mode ) {
			return null;
		}
		if ( 1 !== preg_match( '/^ik_' . $mode . '_[A-Za-z0-9_-]{20,}$/', $secret ) ) {
			return null;
		}
		if ( '' !== $publishable && 1 !== preg_match( '/^pk_' . $mode . '_[A-Za-z0-9_-]{20,}$/', $publishable ) ) {
			return null;
		}
		$merchant   = isset( $response['merchant'] ) && is_array( $response['merchant'] ) ? $response['merchant'] : array();
		$account    = isset( $response['account'] ) && is_array( $response['account'] ) ? $response['account'] : array();
		$connection = isset( $response['connection'] ) && is_array( $response['connection'] ) ? $response['connection'] : array();
		$business   = '';
		if ( isset( $merchant['name'] ) && is_string( $merchant['name'] ) ) {
			$business = $merchant['name'];
		} elseif ( isset( $account['name'] ) && is_string( $account['name'] ) ) {
			$business = $account['name'];
		}
		return array(
			'secret_key'      => $secret,
			'publishable_key' => $publishable,
			'mode'            => $mode,
			'business'        => $business,
			'merchant_status' => isset( $merchant['status'] ) && is_string( $merchant['status'] ) ? $merchant['status'] : '',
			'connection_id'   => isset( $connection['id'] ) && is_string( $connection['id'] ) ? $connection['id'] : '',
			'site'            => isset( $connection['site'] ) && is_string( $connection['site'] ) ? $connection['site'] : '',
		);
	}
}
