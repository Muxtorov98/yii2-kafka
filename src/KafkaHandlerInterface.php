<?php
namespace Muxtorov98\YiiKafka;

/**
 * Yii2 Kafka Worker
 *
 * @package muxtorov98/yii2-kafka
 * @author  Tulqin Muxtorov <tulqin484@gmail.com>
 * @license MIT
 * @link    https://github.com/muxtorov98/yii2-kafka
 */
interface KafkaHandlerInterface
{
    public function handle(array $message): void;
}
