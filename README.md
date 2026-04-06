# yii2-kafka

Kafka integration for Yii2 with:

- handler auto-discovery
- topic + group based worker forking
- retry on handler failure
- DLQ publishing for failed messages
- PSR-3 logger support
- in-memory metrics counters
- idempotent handler contract
- graceful shutdown
- safer producer flush handling

Package: `muxtorov98/yii2-kafka`

## Requirements

- PHP `^8.2`
- Yii2 `^2.0`
- `ext-rdkafka`
- `ext-pcntl`

## Install

```bash
composer require muxtorov98/yii2-kafka
```

## Docker extensions

```dockerfile
RUN pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && docker-php-ext-install pcntl
```

## Config

Create `common/config/kafka.php`

```php
<?php

return [
    'brokers' => 'kafka:9092',
    'consumer' => [
        'auto_commit' => true,
        'auto_offset_reset' => 'earliest',
        'max_poll_interval_ms' => 300000,
        'consume_timeout_ms' => 1000,
        'commit_on_failure' => false,
    ],
    'producer' => [
        'acks' => 'all',
        'compression' => 'lz4',
        'linger_ms' => 1,
        'flush_timeout_ms' => 1000,
        'flush_retries' => 3,
    ],
    'retry' => [
        'max_attempts' => 3,
        'backoff_ms' => 500,
    ],
    'dlq' => [
        'enabled' => true,
        'topic_suffix' => '.dlq',
        'include_error_context' => true,
    ],
    'security' => [
        // 'protocol' => 'SASL_SSL',
        // 'sasl' => [
        //     'mechanism' => 'PLAIN',
        //     'username' => 'user',
        //     'password' => 'secret',
        // ],
        // 'ssl' => [
        //     'ca' => '/etc/ssl/certs/ca.pem',
        // ],
    ],
];
```

## Handler example

`common/kafka/handlers/OrderCreatedHandler.php`

```php
<?php

namespace common\kafka\handlers;

use Muxtorov98\YiiKafka\Attribute\KafkaChannel;
use Muxtorov98\YiiKafka\KafkaHandlerInterface;

#[KafkaChannel(topic: 'order-create', group: 'order-service')]
final class OrderCreatedHandler implements KafkaHandlerInterface
{
    public function handle(array $message): void
    {
        echo 'Order created: ' . json_encode($message, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
```

Important:

- worker only runs handlers matching both `topic` and `group`
- if you want separate consumer groups, define separate handlers with different groups

## Idempotent handler example

```php
<?php

namespace common\kafka\handlers;

use Muxtorov98\YiiKafka\Attribute\KafkaChannel;
use Muxtorov98\YiiKafka\IdempotentKafkaHandlerInterface;

#[KafkaChannel(topic: 'order-create', group: 'order-service')]
final class SafeOrderCreatedHandler implements IdempotentKafkaHandlerInterface
{
    public function uniqueKey(array $message): string
    {
        return (string) ($message['order_id'] ?? '');
    }

    public function handle(array $message): void
    {
        // idempotent processing
    }
}
```

For real production duplicate protection, inject your own persistent store instead of in-memory storage.

## Start worker

```bash
php yii worker/start
```

Example output:

```text
🚀 Kafka Worker starting...
👷 Worker started | topic=order-create, group=order-service, PID=721
👂 Kafka listening: topic(s)=order-create, group=order-service
```

## Publish messages

Controller example:

```php
<?php

namespace console\controllers;

use Muxtorov98\YiiKafka\KafkaPublisher;
use yii\console\Controller;

final class KafkaPublishController extends Controller
{
    public function __construct($id, $module, private KafkaPublisher $publisher, $config = [])
    {
        parent::__construct($id, $module, $config);
    }

    public function actionSend(string $topic, string $json): int
    {
        return $this->publisher->publishSend($topic, $json);
    }

    public function actionBatch(string $topic, string $jsonList): int
    {
        return $this->publisher->publishBatch($topic, $jsonList);
    }
}
```

CLI:

```bash
php yii kafka-publish/send order-create '{"order_id":999}'
php yii kafka-publish/batch order-create '[{"id":1},{"id":2}]'
```

## Failure behavior

- invalid JSON payload in producer throws controlled failure
- producer checks flush result and throws if delivery is not confirmed in configured attempts
- handler failures are retried using `retry.max_attempts`
- if DLQ is enabled, exhausted failures are published to `original-topic.dlq`
- after all retries fail:
  - by default message is not committed
  - if `consumer.commit_on_failure = true`, message is committed after failure

## Observability

Current package supports:

- PSR-3 logger injection
- metrics counters:
  - `processed_count`
  - `failed_count`
  - `retry_count`
  - `dlq_published_count`
  - `skipped_duplicate_count`
  - `consumer_error_count`

Default metrics collector is in-memory and logs snapshot through the configured logger.

## Custom dependency injection

You can pass custom logger, metrics collector, idempotency store, and producer into `Worker`.

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Muxtorov98\YiiKafka\Metrics\InMemoryMetricsCollector;
use Muxtorov98\YiiKafka\Store\ArrayIdempotencyStore;
use Muxtorov98\YiiKafka\Worker;

$logger = new Logger('kafka');
$logger->pushHandler(new StreamHandler('php://stdout'));

$worker = new Worker(
    $options,
    'order-service',
    ['order-create'],
    $logger,
    new InMemoryMetricsCollector(),
    new ArrayIdempotencyStore()
);
```

## Production notes

- keep one logical handler per `topic + group` unless you intentionally want multiple handlers in the same consumer group process
- use `commit_on_failure = false` if you prefer redelivery over loss
- use `commit_on_failure = true` only if poison messages would block the queue and you accept skipping failed messages
- use unique groups for independent business flows
- use persistent idempotency storage if duplicate processing matters
- use supervisor or systemd to auto-restart workers

## Current scope

This package provides a Yii2-focused Kafka worker and producer.

It does not yet provide:

- Prometheus exporter
- persistent idempotency storage implementation
- health endpoint
- systemd template

If you need framework-agnostic multi-bridge architecture, use the separate universal package instead.

## Supervisor example

See:

- [`examples/supervisor/yii2-kafka-worker.conf`](examples/supervisor/yii2-kafka-worker.conf)
