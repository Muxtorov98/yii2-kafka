<?php
namespace Muxtorov98\YiiKafka;

interface IdempotentKafkaHandlerInterface extends KafkaHandlerInterface
{
    public function uniqueKey(array $message): string;
}
