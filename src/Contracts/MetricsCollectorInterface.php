<?php
namespace Muxtorov98\YiiKafka\Contracts;

interface MetricsCollectorInterface
{
    /**
     * @param array<string, scalar|null> $labels
     */
    public function increment(string $metric, int $value = 1, array $labels = []): void;

    /**
     * @return array<string, int>
     */
    public function snapshot(): array;
}
