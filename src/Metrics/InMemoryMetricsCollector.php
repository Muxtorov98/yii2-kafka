<?php
namespace Muxtorov98\YiiKafka\Metrics;

use Muxtorov98\YiiKafka\Contracts\MetricsCollectorInterface;

final class InMemoryMetricsCollector implements MetricsCollectorInterface
{
    /**
     * @var array<string, int>
     */
    private array $counters = [];

    public function increment(string $metric, int $value = 1, array $labels = []): void
    {
        $key = $metric;
        if ($labels !== []) {
            ksort($labels);
            $key .= ':' . json_encode($labels, JSON_UNESCAPED_UNICODE);
        }

        if (!isset($this->counters[$key])) {
            $this->counters[$key] = 0;
        }

        $this->counters[$key] += $value;
    }

    public function snapshot(): array
    {
        return $this->counters;
    }
}
