<?php
namespace Muxtorov98\YiiKafka;

use RdKafka\KafkaConsumer;
use Throwable;

final class Worker
{
    public function __construct(
        private KafkaOptions $options,
        private string $group,
        private array $handlers
    ) {}

    public function run(string $topic): void
    {
        echo "👂 Kafka → topic={$topic}, group={$this->group}\n";

        $consumer = new KafkaConsumer($this->options->consumerConf());
        $consumer->subscribe([$topic]);

        declare(ticks=1);
        pcntl_signal(SIGINT, fn() => exit("⛔ Stopped\n"));

        while (true) {
            $message = $consumer->consume(1000);
            if (!$message || $message->err) continue;

            $payload = json_decode($message->payload, true);

            foreach ($this->handlers as $class) {
                $h = new $class();
                if (!$h instanceof KafkaHandlerInterface) continue;

                $attempt = 0;
                while ($attempt++ < ($this->options->retry['max_attempts'] ?? 3)) {
                    try {
                        $h->handle($payload);
                        break;
                    } catch (Throwable $e) {
                        echo "⚠️ Retry {$attempt}: {$e->getMessage()}\n";
                        usleep(($this->options->retry['backoff_ms'] ?? 500) * 1000);
                    }
                }
            }
        }
    }
}
