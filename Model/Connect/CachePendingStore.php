<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Connect;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Pending Connect requests in Magento's cache, for at most half an hour (the protocol's TTL).
 *
 * The request is kept per admin user, like the WooCommerce plugin's transient. The storefront
 * half of the round trip has no admin session, so the admin URL a `state` returns to is kept
 * under a hash of the state. Flushing the cache mid-way only means pressing Connect again.
 */
class CachePendingStore implements PendingStoreInterface
{
    private const PREFIX = 'intentsfi_connect_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    public function put(int $userId, array $pending, int $ttl): void
    {
        $this->cache->save((string) $this->serializer->serialize($pending), self::userKey($userId), [], $ttl);
    }

    public function take(int $userId): ?array
    {
        $raw = $this->cache->load(self::userKey($userId));
        $this->cache->remove(self::userKey($userId));
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $pending = $this->serializer->unserialize($raw);
        return is_array($pending) ? $pending : null;
    }

    public function putReturn(string $state, string $callbackUrl, int $ttl): void
    {
        $this->cache->save($callbackUrl, self::stateKey($state), [], $ttl);
    }

    public function returnFor(string $state): ?string
    {
        $url = $this->cache->load(self::stateKey($state));
        return is_string($url) && $url !== '' ? $url : null;
    }

    private static function userKey(int $userId): string
    {
        return self::PREFIX . 'user_' . $userId;
    }

    private static function stateKey(string $state): string
    {
        return self::PREFIX . 'state_' . hash('sha256', $state);
    }
}
