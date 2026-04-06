<?php
namespace Muxtorov98\YiiKafka;

use RdKafka\KafkaConsumer;
use RdKafka\Message;
use ReflectionClass;
use RuntimeException;
use Throwable;
use Yii;
use yii\helpers\Json;

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
        foreach ($this->findPhpFiles($handlersPath) as $file) {
            require_once $file;
            $fqcn = $this->guessFQCN($file);
            if (!$fqcn || !class_exists($fqcn)) {
                continue;
            }

            $ref = new ReflectionClass($fqcn);
            $attrs = $ref->getAttributes(Attribute\KafkaChannel::class);
            if (!$attrs) {
                continue;
            }

            $meta = $attrs[0]->newInstance();

            if (!in_array($meta->topic, $this->topics, true) || $meta->group !== $this->group) {
                continue;
            }

            $obj = class_exists(Yii::class) ? Yii::createObject($fqcn) : new $fqcn();
            if ($obj instanceof KafkaHandlerInterface) {
                $this->handlerObjects[] = $obj;
            }
        }
    }

    public function start(): void
    {
        echo "👂 Kafka listening: topic(s)=".implode(',', $this->topics).", group={$this->group}\n";

        if ($this->handlerObjects === []) {
            throw new RuntimeException(sprintf(
                'No Kafka handlers registered for topic(s) [%s] and group [%s].',
                implode(',', $this->topics),
                $this->group
            ));
        }

        $this->consumer = new KafkaConsumer($this->options->consumerConf($this->group));
        $this->consumer->subscribe($this->topics);

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, fn() => $this->stop());
        pcntl_signal(SIGTERM, fn() => $this->stop());

        while ($this->running) {
            $msg = $this->consumer->consume($this->options->consumeTimeoutMs());
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
        try {
            $payload = Json::decode($msg->payload, true);
            if (!is_array($payload)) {
                throw new RuntimeException('Kafka payload must decode to array.');
            }

            foreach ($this->handlerObjects as $handler) {
                $this->runHandlerWithRetry($handler, $payload, $msg);
            }

            $this->consumer->commit($msg);
        } catch (Throwable $e) {
            echo sprintf(
                "❌ Handler failed | topic=%s, group=%s, error=%s\n",
                $msg->topic_name,
                $this->group,
                $e->getMessage()
            );

            if ($this->options->commitOnFailure()) {
                $this->consumer->commit($msg);
            }
        }
    }

    private function guessFQCN(string $file): ?string
    {
        $code = file_get_contents($file);
        if ($code === false) {
            return null;
        }

        preg_match('/namespace\s+([^;]+);/', $code, $ns);
        preg_match('/class\s+([^\s]+)/', $code, $cl);
        if (!isset($ns[1], $cl[1])) {
            return null;
        }

        return $ns[1] . '\\' . $cl[1];
    }

    private function runHandlerWithRetry(KafkaHandlerInterface $handler, array $payload, Message $msg): void
    {
        $maxAttempts = $this->options->retryMaxAttempts();
        $backoffMs = $this->options->retryBackoffMs();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $handler->handle($payload);
                return;
            } catch (Throwable $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                echo sprintf(
                    "⚠️ Handler retry | topic=%s, group=%s, attempt=%d/%d, error=%s\n",
                    $msg->topic_name,
                    $this->group,
                    $attempt,
                    $maxAttempts,
                    $e->getMessage()
                );

                if ($backoffMs > 0) {
                    usleep($backoffMs * 1000);
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function findPhpFiles(string $handlersPath): array
    {
        if (!is_dir($handlersPath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($handlersPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function stop(): void
    {
        $this->running = false;
        if (isset($this->consumer)) {
            $this->consumer->close();
        }
    }
}
