<?php
namespace Muxtorov98\YiiKafka\Attribute;

use Attribute;

/**
 * Yii2 Kafka Worker
 *
 * @package muxtorov98/yii2-kafka
 * @author  Tulqin Muxtorov <tulqin484@gmail.com>
 * @license MIT
 * @link    https://github.com/muxtorov98/yii2-kafka
 */
#[Attribute(Attribute::TARGET_CLASS)]
class KafkaChannel
{
    public function __construct(
        public string $topic,
        public string $group = 'yii2-worker-group'
    ) {}
}
