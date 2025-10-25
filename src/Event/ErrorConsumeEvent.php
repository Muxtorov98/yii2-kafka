<?php
namespace Muxtorov98\YiiKafka\Event;

class ErrorConsumeEvent
{
    public function __construct(
        public string $topic,
        public string $error
    ) {}
}
