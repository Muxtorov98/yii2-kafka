<?php
namespace Muxtorov98\YiiKafka;

use RdKafka\KafkaConsumer;
use RdKafka\Message;
use Throwable;

/**
 * Yii2 Kafka Worker
 *
 * @package muxtorov98/yii2-kafka
 * @author  Tulqin Muxtorov <tulqin484@gmail.com>
 * @license MIT
 * @link    https://github.com/muxtorov98/yii2-kafka
 */
final class Worker
{
    private KafkaConsumer $consumer;
    private array $handlerObjects = [];
    private bool $running = true;
    private array $topics;

    public function __construct(
        private KafkaOptions $options,
        private string $group,
        array $topics
    ) {
        $this->topics = $topics;
    }

    public function registerHandlers(string $handlersPath): void
    {
        foreach (glob($handlersPath . '/*.php') as $file) {
            require_once $file;
            $fqcn = $this->guessFQCN($file);
            if (!$fqcn || !class_exists($fqcn)) continue;

            $ref = new \ReflectionClass($fqcn);
            $attrs = $ref->getAttributes(Attribute\KafkaChannel::class);
            if (!$attrs) continue;

            $meta = $attrs[0]->newInstance();

            if (!in_array($meta->topic, $this->topics, true)) continue;

            $obj = new $fqcn();
            if ($obj instanceof KafkaHandlerInterface) {
                $this->handlerObjects[] = $obj;
            }
        }
    }

    public function start(): void
    {
        echo "👂 Kafka listening: topic(s)=".implode(',', $this->topics).", group={$this->group}\n";

        $this->consumer = new KafkaConsumer($this->options->consumerConf());
        $this->consumer->subscribe($this->topics);

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, fn() => $this->stop());
        pcntl_signal(SIGTERM, fn() => $this->stop());

        while ($this->running) {
            $msg = $this->consumer->consume(1000);
            if (!$msg) continue;

            switch ($msg->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->processMessage($msg);
                    break;
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    break;
                default:
                    echo "❌ Kafka error: {$msg->errstr()}\n";
                    break;
            }
        }
        echo "🛑 Worker stopped\n";
    }

    private function processMessage(Message $msg): void
    {
        $payload = json_decode($msg->payload, true);

        foreach ($this->handlerObjects as $handler) {
            $handler->handle($payload);
        }

        $this->consumer->commit($msg);
    }

    private function guessFQCN(string $file): ?string
    {
        $code = file_get_contents($file);
        preg_match('/namespace\s+([^;]+);/', $code, $ns);
        preg_match('/class\s+([^\s]+)/', $code, $cl);
        return ($ns[1] ?? null) . '\\' . ($cl[1] ?? null);
    }

    private function stop(): void
    {
        $this->running = false;
        $this->consumer->close();
    }
}
