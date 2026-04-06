<?php
namespace Muxtorov98\YiiKafka\Store;

use Muxtorov98\YiiKafka\Contracts\IdempotencyStoreInterface;

final class ArrayIdempotencyStore implements IdempotencyStoreInterface
{
    /**
     * @var array<string, bool>
     */
    private array $processed = [];

    public function has(string $key): bool
    {
        return isset($this->processed[$key]);
    }

    public function markProcessed(string $key): void
    {
        $this->processed[$key] = true;
    }
}
