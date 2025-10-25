<?php
namespace Muxtorov98\YiiKafka;

use RdKafka\Producer as RdProducer;

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
        $topicObj = $this->producer->newTopic($topic);
        $topicObj->produce(RD_KAFKA_PARTITION_UA, 0, $json);
        $this->producer->poll(0);
        $this->producer->flush(1000);
    }
}
