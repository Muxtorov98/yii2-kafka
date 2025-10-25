<?php
namespace Muxtorov98\YiiKafka\Helpers;

class Logger
{
    public static function info(string $msg): void
    {
        echo "ℹ️ $msg\n";
    }

    public static function error(string $msg): void
    {
        echo "❌ $msg\n";
    }
}
