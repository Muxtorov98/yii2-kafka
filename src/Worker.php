<?php
namespace Muxtorov98\YiiKafka;

use RdKafka\KafkaConsumer;
use RdKafka\Message;
use Throwable;

final class Worker
{
    private KafkaConsumer $consumer;
    private array $handlerObjects = [];
    private bool $running = true;

    public function __construct(
        private KafkaOptions $options,
        private string $group,
        private array $handlers
    ) {
        foreach ($handlers as $class) {
            $obj = new $class();
            if ($obj instanceof KafkaHandlerInterface) {
                $this->handlerObjects[] = $obj;
            }
        }
    }

    public function run(string $topic): void
    {
        echo "👂 Kafka → topic={$topic}, group={$this->group}\n";

        $this->consumer = new KafkaConsumer($this->options->consumerConf());
        $this->consumer->subscribe([$topic]);

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, fn() => $this->shutdown());
        pcntl_signal(SIGTERM, fn() => $this->shutdown());

        while ($this->running) {
            $msg = $this->consumer->consume(1000);
            if (!$msg) {
                continue;
            }

            switch ($msg->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->processMessage($msg);
                    break;

                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    break; // normal condition

                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    echo "⏳ Poll timeout...\n";
                    break;

                default:
                    echo "❌ Kafka error: {$msg->errstr()}\n";
                    $this->shutdown();
                    break;
            }
        }
    }

    private function processMessage(Message $msg): void
    {
        $payload = json_decode($msg->payload, true);

        foreach ($this->handlerObjects as $handler) {
            $attempt = 0;
            while ($attempt++ < ($this->options->retry['max_attempts'] ?? 3)) {
                try {
                    $handler->handle($payload);
                    $this->consumer->commit($msg);
                    return;
                } catch (Throwable $e) {
                    echo "⚠️ Retry {$attempt}: {$e->getMessage()}\n";
                    usleep(($this->options->retry['backoff_ms'] ?? 500) * 1000);
                }
            }
        }
    }

    private function shutdown(): void
    {
        echo "\n👋 Worker stopping gracefully...\n";
        $this->running = false;
        $this->consumer->close();
    }
}
