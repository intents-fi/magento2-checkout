<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Api;

/** One HTTP round trip. The Magento implementation is {@see CurlTransport}. */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws \RuntimeException When the request could not be made at all.
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array;
}
