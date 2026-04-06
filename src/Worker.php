<?php
namespace Muxtorov98\YiiKafka;

use Muxtorov98\YiiKafka\Contracts\IdempotencyStoreInterface;
use Muxtorov98\YiiKafka\Contracts\MetricsCollectorInterface;
use Muxtorov98\YiiKafka\Metrics\InMemoryMetricsCollector;
use Muxtorov98\YiiKafka\Store\NullIdempotencyStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
    private LoggerInterface $logger;
    private MetricsCollectorInterface $metrics;
    private IdempotencyStoreInterface $idempotencyStore;
    private Producer $producer;

    public function __construct(
        private KafkaOptions $options,
        private string $group,
        array $topics,
        ?LoggerInterface $logger = null,
        ?MetricsCollectorInterface $metrics = null,
        ?IdempotencyStoreInterface $idempotencyStore = null,
        ?Producer $producer = null
    ) {
        $this->topics = $topics;
        $this->logger = $logger ?? new NullLogger();
        $this->metrics = $metrics ?? new InMemoryMetricsCollector();
        $this->idempotencyStore = $idempotencyStore ?? new NullIdempotencyStore();
        $this->producer = $producer ?? new Producer($this->options);
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
        $message = "Kafka listening: topic(s)=".implode(',', $this->topics).", group={$this->group}";
        $this->logger->info($message, ['topics' => $this->topics, 'group' => $this->group]);
        echo "👂 {$message}\n";

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
                    $this->metrics->increment('consumer_error_count', 1, ['group' => $this->group]);
                    $this->logger->error('Kafka consumer error.', [
                        'group' => $this->group,
                        'topics' => $this->topics,
                        'error' => $msg->errstr(),
                    ]);
                    echo "❌ Kafka error: {$msg->errstr()}\n";
                    break;
            }
        }
        $this->logMetricsSnapshot();
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
            $this->metrics->increment('processed_count', 1, ['group' => $this->group, 'topic' => $msg->topic_name]);
        } catch (Throwable $e) {
            $this->metrics->increment('failed_count', 1, ['group' => $this->group, 'topic' => $msg->topic_name]);
            $this->logger->error('Kafka handler failed.', [
                'topic' => $msg->topic_name,
                'group' => $this->group,
                'error' => $e->getMessage(),
            ]);
            echo sprintf(
                "❌ Handler failed | topic=%s, group=%s, error=%s\n",
                $msg->topic_name,
                $this->group,
                $e->getMessage()
            );

            $this->publishToDlq($msg, $e);

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
        $handlerName = $handler::class;

        if ($handler instanceof IdempotentKafkaHandlerInterface) {
            $idempotencyKey = $this->buildIdempotencyKey($handler, $payload);
            if ($this->idempotencyStore->has($idempotencyKey)) {
                $this->metrics->increment('skipped_duplicate_count', 1, [
                    'group' => $this->group,
                    'topic' => $msg->topic_name,
                    'handler' => $handlerName,
                ]);
                $this->logger->info('Kafka duplicate skipped.', [
                    'topic' => $msg->topic_name,
                    'group' => $this->group,
                    'handler' => $handlerName,
                    'idempotency_key' => $idempotencyKey,
                ]);
                return;
            }
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $handler->handle($payload);
                if ($handler instanceof IdempotentKafkaHandlerInterface) {
                    $this->idempotencyStore->markProcessed($this->buildIdempotencyKey($handler, $payload));
                }
                return;
            } catch (Throwable $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                $this->metrics->increment('retry_count', 1, [
                    'group' => $this->group,
                    'topic' => $msg->topic_name,
                    'handler' => $handlerName,
                ]);
                $this->logger->warning('Kafka handler retry scheduled.', [
                    'topic' => $msg->topic_name,
                    'group' => $this->group,
                    'handler' => $handlerName,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ]);
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

    private function publishToDlq(Message $msg, Throwable $e): void
    {
        if (!$this->options->dlqEnabled()) {
            return;
        }

        $payload = [
            'original_topic' => $msg->topic_name,
            'group' => $this->group,
            'payload' => $msg->payload,
        ];

        if ($this->options->dlqIncludeErrorContext()) {
            $payload['error'] = [
                'message' => $e->getMessage(),
                'type' => $e::class,
            ];
        }

        $dlqTopic = $msg->topic_name . $this->options->dlqTopicSuffix();

        try {
            $this->producer->send($dlqTopic, $payload);
            $this->metrics->increment('dlq_published_count', 1, ['group' => $this->group, 'topic' => $msg->topic_name]);
            $this->logger->error('Kafka message sent to DLQ.', [
                'topic' => $msg->topic_name,
                'group' => $this->group,
                'dlq_topic' => $dlqTopic,
            ]);
        } catch (Throwable $dlqError) {
            $this->logger->critical('Kafka DLQ publish failed.', [
                'topic' => $msg->topic_name,
                'group' => $this->group,
                'dlq_topic' => $dlqTopic,
                'error' => $dlqError->getMessage(),
            ]);
            echo sprintf(
                "❌ DLQ publish failed | topic=%s, group=%s, error=%s\n",
                $msg->topic_name,
                $this->group,
                $dlqError->getMessage()
            );
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
        $this->logMetricsSnapshot();
    }

    private function buildIdempotencyKey(IdempotentKafkaHandlerInterface $handler, array $payload): string
    {
        return implode(':', [
            $this->group,
            $handler::class,
            $handler->uniqueKey($payload),
        ]);
    }

    private function logMetricsSnapshot(): void
    {
        $snapshot = $this->metrics->snapshot();
        if ($snapshot === []) {
            return;
        }

        $this->logger->info('Kafka worker metrics snapshot.', [
            'group' => $this->group,
            'metrics' => $snapshot,
        ]);
    }
}
