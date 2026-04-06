<?php
namespace Muxtorov98\YiiKafka\Store;

use Muxtorov98\YiiKafka\Contracts\IdempotencyStoreInterface;

final class NullIdempotencyStore implements IdempotencyStoreInterface
{
    public function has(string $key): bool
    {
        return false;
    }

    public function markProcessed(string $key): void
    {
    }
}
