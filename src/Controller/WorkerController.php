<?php
namespace Muxtorov98\YiiKafka\Controller;

use yii\console\Controller;
use Muxtorov98\YiiKafka\{KafkaOptions, Worker};
use Muxtorov98\YiiKafka\Attribute\KafkaChannel;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use ReflectionClass;

/**
 * Yii2 Kafka Worker
 *
 * @package muxtorov98/yii2-kafka
 * @author  Tulqin Muxtorov <tulqin484@gmail.com>
 * @license MIT
 * @link    https://github.com/muxtorov98/yii2-kafka
 */
final class WorkerController extends Controller
{
    public $defaultAction = 'start';

    public function actionStart(): void
    {
        echo "🚀 Kafka Worker starting...\n";

        $options = KafkaOptions::fromArray(
            require \Yii::getAlias('@common/config/kafka.php')
        );

        $handlersPath = \Yii::getAlias('@common/kafka/handlers');
        $map = $this->discoverHandlers($handlersPath);

        foreach ($map as $topic => $groups) {
            foreach ($groups as $group) {

                $pid = pcntl_fork();

                if ($pid === 0) { // child process
                    echo "👷 Worker started | topic={$topic}, group={$group}, PID=" . getmypid() . "\n";

                    $worker = new Worker($options, $group, [$topic]);
                    $worker->registerHandlers($handlersPath);
                    $worker->start();

                    exit(0);
                }
            }
        }

        while (pcntl_wait($st) > 0);
    }

    private function discoverHandlers(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $map = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;

            $class = $this->classFromFile($file->getPathname());
            if (!$class || !class_exists($class)) continue;

            $ref = new ReflectionClass($class);
            $attrs = $ref->getAttributes(KafkaChannel::class);
            if (!$attrs) continue;

            /** @var KafkaChannel $meta */
            $meta = $attrs[0]->newInstance();

            $map[$meta->topic][] = $meta->group;
        }

        return $map;
    }

    private function classFromFile(string $path): ?string
    {
        $src = file_get_contents($path);
        preg_match('/namespace\s+([^;]+);/', $src, $ns);
        preg_match('/class\s+([^\s]+)/', $src, $cl);
        return ($ns && $cl) ? $ns[1] . '\\' . $cl[1] : null;
    }
}
