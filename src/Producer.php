<?php
namespace Muxtorov98\YiiKafka;

use RdKafka\Producer as RdProducer;
use RuntimeException;

/**
 * Yii2 Kafka Worker
 *
 * @package muxtorov98/yii2-kafka
 * @author  Tulqin Muxtorov <tulqin484@gmail.com>
 * @license MIT
 * @link    https://github.com/muxtorov98/yii2-kafka
 */
final class Producer
{
    private RdProducer $producer;

    public function __construct(private KafkaOptions $options)
    {
        $this->producer = new RdProducer($this->options->producerConf());
    }

    public function send(string $topic, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Kafka payload could not be encoded to JSON.');
        }

        $topicObj = $this->producer->newTopic($topic);
        $topicObj->produce(RD_KAFKA_PARTITION_UA, 0, $json);
        $this->producer->poll(0);

        $result = RD_KAFKA_RESP_ERR__TIMED_OUT;
        for ($attempt = 0; $attempt < $this->options->producerFlushRetries(); $attempt++) {
            $result = $this->producer->flush($this->options->producerFlushTimeoutMs());
            if ($result === RD_KAFKA_RESP_ERR_NO_ERROR) {
                return;
            }
        }

        throw new RuntimeException(sprintf(
            'Kafka producer flush failed for topic "%s" after %d attempt(s).',
            $topic,
            $this->options->producerFlushRetries()
        ));
    }
}
