<?php
namespace Muxtorov98\YiiKafka;

use Yii;
use yii\helpers\Json;

final class KafkaPublisher
{
    private Producer $producer;

    public function __construct()
    {
        $options = KafkaOptions::fromArray(
            require Yii::getAlias('@common/config/kafka.php')
        );

        $this->producer = new Producer($options);
    }

    /**
     * Single message publish
     */
    public function publishSend(string $topic, string $json): int
    {
        try {
            $payload = Json::decode($json, true);
        } catch (\Throwable $e) {
            echo "❌ JSON xato: {$e->getMessage()}\n";
            return 1;
        }

        $this->producer->send($topic, $payload);
        echo "✅ Send → {$topic}\n";
        return 0;
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
            echo "❌ JSON xato: {$e->getMessage()}\n";
            return 1;
        }

        foreach ($items as $row) {
            $this->producer->send($topic, $row);
        }

        echo "✅ Batch → {$topic} : " . count($items) . " xabar\n";
        return 0;
    }
}
