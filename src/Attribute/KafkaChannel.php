<?php
namespace Muxtorov98\YiiKafka\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class KafkaChannel
{
    public function __construct(
        public string $topic,
        public string $group = 'yii2-worker-group'
    ) {}
}
