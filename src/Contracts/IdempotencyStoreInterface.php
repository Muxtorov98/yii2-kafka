<?php
namespace Muxtorov98\YiiKafka\Contracts;

interface IdempotencyStoreInterface
{
    public function has(string $key): bool;

    public function markProcessed(string $key): void;
}
