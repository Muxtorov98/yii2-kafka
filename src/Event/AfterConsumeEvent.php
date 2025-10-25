<?php
namespace Muxtorov98\YiiKafka\Event;

class AfterConsumeEvent
{
    public function __construct(
        public string $topic,
        public string $payload
    ) {}
}
