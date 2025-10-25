# 🐘 Yii2 Kafka Worker — Quick Documentation

**Kafka integration for Yii2** — Auto Worker Discovery, Multi Group, Retry, Graceful Shutdown ✅  
Package: `muxtorov98/yii2-kafka`

---

## 🚀 Installation

## Kafka + Zookeeper + Kafka UI — Docker Compose Setup
https://github.com/Muxtorov98/docker-compose-kafka.yml

## 🧩 PHP uchun Kafka Extension (rdkafka) o‘rnatish

Kafka bilan ishlash uchun php-rdkafka extension talab etiladi.Bu extension librdkafka kutubxonasiga asoslanadi va Kafka producer / consumer funksiyalarini PHP orqali amalga oshirishga imkon beradi.

## 🐳 Docker muhiti uchun

```dockerfile
# --- Kafka extension (rdkafka) ---
RUN pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && rm -rf /tmp/pear

# --- PCNTL extension (background process control) ---
RUN docker-php-ext-install pcntl
```

## Izoh:

- rdkafka — Kafka bilan ishlash uchun asosiy extension

- pcntl — workerlarni parallel ishlashini (multi-process) ta’minlaydi

## 🖥️ Ubuntu’da o‘rnatish

```bash
sudo apt update
sudo apt install -y php-dev librdkafka-dev librssl-dev build-essential

sudo pecl install rdkafka
echo "extension=rdkafka.so" | sudo tee /etc/php/$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")/mods-available/rdkafka.ini
sudo phpenmod rdkafka

# PCNTL moduli
sudo docker-php-ext-install pcntl  # agar dockerda bo‘lmasa
```

## Keyin PHP versiyasini tekshiring:

```bash
php -m | grep rdkafka
```
- Agar rdkafka va pcntl ko‘rinsa — hammasi tayyor ✅


## yii2 compose install

```bash
composer require muxtorov98/yii2-kafka
```

---

---

## ⚙️ Kafka Configuration (`common/config/kafka.php`)

```php
<?php

return [
    // Kafka broker
    'brokers' => 'kafka:9092',

    // ✅ Consumer settings
    'consumer' => [
        'auto_commit' => true,              // offsetlarni avtomatik commit
        'auto_offset_reset' => 'earliest',  // yangi group bo‘lsa boshidan o‘qiydi
        'max_poll_interval_ms' => 300000,   // 5 daqiqa
        // 'group.id' => 'override-ham-bo‘ladi' // Worker o‘zi ham group oladi
    ],

    // ✅ Producer settings
    'producer' => [
        'acks' => 'all',
        'compression' => 'lz4',
        'linger_ms' => 1,
    ],

    // ✅ Retry logika (WorkerException bo‘lsa)
    'retry' => [
        'max_attempts' => 3,
        'backoff_ms' => 500, // retry delay
    ],

    // ✅ Agar SASL/SSL bo‘lsa (optional)
    'security' => [
        // 'protocol' => 'SASL_SSL',
        // 'sasl' => [
        //     'mechanism' => 'PLAIN',
        //     'username'  => 'admin',
        //     'password'  => 'secret',
        // ],
        // 'ssl' => [
        //     'ca' => '/etc/ssl/certs/ca.pem',
        // ],
    ],
];

```

---

## 📂 Handlers Joylashuvi

### `common/kafka/handlers/OrderCreatedHandler.php`

```php
<?php

namespace common\kafka\handlers;

use Muxtorov98\YiiKafka\KafkaHandlerInterface;
use Muxtorov98\YiiKafka\Attribute\KafkaChannel;

#[KafkaChannel(topic: 'order-create', group: 'order-service')]
class OrderCreatedHandler implements KafkaHandlerInterface
{
    public function handle(array $message): void
    {
        echo "Order created: " . json_encode($message) . PHP_EOL;
    }
}
```

---

### `common/kafka/handlers/OrderHistoryHandler.php`

```php
<?php

namespace common\kafka\handlers;

use Muxtorov98\YiiKafka\KafkaHandlerInterface;
use Muxtorov98\YiiKafka\Attribute\KafkaChannel;

#[KafkaChannel(topic: 'order-create', group: 'order-history')]
class OrderHistoryHandler implements KafkaHandlerInterface
{
    public function handle(array $message): void
    {
        echo "📦 History saved: " . json_encode($message) . PHP_EOL;
    }
}
```

---

### `common/kafka/handlers/OrderAnalyticsHandler.php`

```php
<?php

namespace common\kafka\handlers;

use Muxtorov98\YiiKafka\KafkaHandlerInterface;
use Muxtorov98\YiiKafka\Attribute\KafkaChannel;

#[KafkaChannel(topic: 'order-create', group: 'analytics-group')]
class OrderAnalyticsHandler implements KafkaHandlerInterface
{
    public function handle(array $message): void
    {
        echo "📊 Analytics updated: " . json_encode($message) . PHP_EOL;
    }
}
```

---

## 🚀 Worker Ishga Tushirish

```bash
php yii worker/start
```

**Avtomatik aniqlash va forking:**

```
🚀 Kafka Worker starting...
👷 Worker started | topic=order-create, group=order-service, PID=721
👷 Worker started | topic=order-create, group=order-history, PID=722
👷 Worker started | topic=order-create, group=analytics-group, PID=723
```

---

## 📨 Xabar Yuborish (Publish)

```php
<?php
declare(strict_types=1);

namespace console\controllers;

use yii\console\Controller;
use Muxtorov98\YiiKafka\Services\KafkaPublisher;

final class PublishController extends Controller
{
    public function __construct(
        $id,
        $module,
        private KafkaPublisher $publisher,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public $defaultAction = 'send';

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
📤 Single
```bash

php yii kafka-publish/send order-create '{"order_id":999}'
```

📦 Batch:
```bash
php yii kafka-publish/batch order-create '[{"id":1},{"id":2}]'

```

**Natija:**

```
✅ Message sent to topic "order-create"
```

---

## 🧩 Natijaviy Ishlash

Yuborilgan bitta xabar **uchta** handler tomonidan qayta ishlanadi:

```
Order created: {"order_id":999}
📦 History saved: {"order_id":999}
📊 Analytics updated: {"order_id":999}
```

---

## 🧠 Features

✅ Auto Worker Discovery  
✅ Multi Group Consumer  
✅ Graceful Shutdown  
✅ Retry & Backoff Strategy  
✅ LZ4 Compression Support  
✅ Symfony-style Attribute Mapping

---

## 📜 License

MIT License © [Muxtorov98](https://github.com/muxtorov98)
