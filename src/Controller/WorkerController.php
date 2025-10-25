<?php
namespace Muxtorov98\YiiKafka\Controller;

use yii\console\Controller;
use Muxtorov98\YiiKafka\{
    KafkaOptions,
    Worker
};
use Muxtorov98\YiiKafka\Attribute\KafkaChannel;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use ReflectionClass;

final class WorkerController extends Controller
{
    public $defaultAction = 'start';

    public function actionStart(): void
    {
        echo "🚀 Kafka Worker starting...\n";

        $config = require \Yii::getAlias('@common/config/kafka.php');
        $options = KafkaOptions::fromArray($config);

        $handlersPath = \Yii::getAlias('@common/kafka/handlers');

        $handlerMap = $this->discoverHandlers($handlersPath);

        if (empty($handlerMap)) {
            echo "⚠️ No Kafka handlers found in {$handlersPath}\n";
            return;
        }

        foreach ($handlerMap as $topic => $group) {
            echo "👷 Worker listening: topic={$topic}, group={$group}\n";

            if (pcntl_fork() === 0) {
                $worker = new Worker($options, $group, [$topic]);
                $worker->registerHandlers($handlersPath);
                $worker->start();
                exit(0);
            }
        }

        while (pcntl_wait($status) > 0);
    }

    private function discoverHandlers(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $result = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;

            $class = $this->findClassName($file->getPathname());
            if (!$class || !class_exists($class)) continue;

            $ref = new ReflectionClass($class);
            $attrs = $ref->getAttributes(KafkaChannel::class);
            if (!$attrs) continue;

            $meta = $attrs[0]->newInstance();
            $result[$meta->topic] = $meta->group;
        }

        return $result;
    }

    private function findClassName(string $file): ?string
    {
        $content = file_get_contents($file);
        preg_match('/namespace\s+([^;]+);/', $content, $ns);
        preg_match('/class\s+([^\s]+)/', $content, $cl);

        return isset($ns[1], $cl[1]) ? $ns[1] . '\\' . $cl[1] : null;
    }
}
