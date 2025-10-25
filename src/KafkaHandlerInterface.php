<?php
namespace Muxtorov98\YiiKafka;

interface KafkaHandlerInterface
{
    public function handle(array $message): void;
}
