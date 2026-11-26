<?php
/**
 * Loads the shared Connect protocol class.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Connect;

/**
 * The plugin half of intents.fi Connect is one PHP class shared by every store plugin:
 * `plugins/_shared/connect/class-connect-protocol.php` in the intents.fi repository. It is not
 * forked here: `lib/connect/class-connect-protocol.php` is a byte-identical copy (as WooCommerce
 * keeps one), enforced by a unit test and by bin/build-dist.sh, so every plugin runs the same
 * checks and the module's own repository (the Packagist mirror) is complete.
 *
 * It is a plain class in the `IntentsFi\Connect` namespace, outside this module's PSR-4 root. A
 * Composer install (the Marketplace package) autoloads it through composer.json's `classmap`, so
 * the require below never runs there. A zip unpacked into app/code has no Composer autoload of its
 * own, so the file is loaded by path, once, from a constant path inside the module: no variable,
 * no URL and nothing a request can influence.
 */
class Protocol
{
    /** The module's copy. */
    public const FILE = __DIR__ . '/../../lib/connect/class-connect-protocol.php';

    /** The canonical copy, in the intents.fi repository (tests compare the two). */
    public const SHARED = __DIR__ . '/../../../_shared/connect/class-connect-protocol.php';

    public static function load(): void
    {
        if (class_exists(\IntentsFi\Connect\Connect_Protocol::class)) {
            return;
        }
        // A fixed file shipped in this module (see above), loaded only when Composer has not.
        // phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile
        require_once self::FILE;
    }
}
