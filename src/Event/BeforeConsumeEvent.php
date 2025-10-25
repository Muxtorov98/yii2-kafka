<?php
namespace Muxtorov98\YiiKafka\Event;

class BeforeConsumeEvent
{
    public function __construct(
        public string $topic,
        public string $payload
    ) {}
}
