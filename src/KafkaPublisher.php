<?php
namespace Muxtorov98\YiiKafka;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
final class KafkaPublisher
{
    private Producer $producer;
    private LoggerInterface $logger;

    public function __construct(?Producer $producer = null, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        if ($producer !== null) {
            $this->producer = $producer;
            return;
        }

        $options = KafkaOptions::fromArray(require Yii::getAlias('@common/config/kafka.php'));
        $this->producer = new Producer($options);
    }

    /**
     * Single message publish
     */
    public function publishSend(string $topic, string $json): int
    {
        try {
            $payload = Json::decode($json, true);
            if (!is_array($payload)) {
                throw new \RuntimeException('JSON object bo‘lishi kerak.');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Kafka publish JSON decode failed.', ['topic' => $topic, 'error' => $e->getMessage()]);
            echo "❌ JSON xato: {$e->getMessage()}\n";
            return 1;
        }

        try {
            $this->producer->send($topic, $payload);
            $this->logger->info('Kafka message published.', ['topic' => $topic]);
            echo "✅ Send → {$topic}\n";
            return 0;
        } catch (\Throwable $e) {
            $this->logger->error('Kafka publish failed.', ['topic' => $topic, 'error' => $e->getMessage()]);
            echo "❌ Kafka publish xato: {$e->getMessage()}\n";
            return 1;
        }
    }

    /**
     * Multiple messages publish (batch)
     */
    public function publishBatch(string $topic, string $jsonList): int
    {
        try {
            $items = Json::decode($jsonList, true);

            if (!is_array($items)) {
                throw new \Exception("Batch uchun JSON array bo‘lishi kerak!");
            }
        } catch (\Throwable $e) {
            $this->logger->error('Kafka batch JSON decode failed.', ['topic' => $topic, 'error' => $e->getMessage()]);
            echo "❌ JSON xato: {$e->getMessage()}\n";
            return 1;
        }

        try {
            foreach ($items as $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException('Batch item array bo‘lishi kerak.');
                }
                $this->producer->send($topic, $row);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Kafka batch publish failed.', ['topic' => $topic, 'error' => $e->getMessage()]);
            echo "❌ Kafka batch xato: {$e->getMessage()}\n";
            return 1;
        }

        $this->logger->info('Kafka batch published.', ['topic' => $topic, 'count' => count($items)]);
        echo "✅ Batch → {$topic} : " . count($items) . " xabar\n";
        return 0;
    }
}
