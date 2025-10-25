<?php
namespace Muxtorov98\YiiKafka\Controller;

use yii\console\Controller;
use Muxtorov98\YiiKafka\{KafkaOptions, Worker};
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

        $options = KafkaOptions::fromArray(
            require \Yii::getAlias('@common/config/kafka.php')
        );

        // ✅ Siz tanlagan to‘g‘ri joy
        $handlersPath = \Yii::getAlias('@common/kafka/handlers');
        $handlerMap = $this->discoverHandlers($handlersPath);

        foreach ($handlerMap as $topic => $conf) {
            echo "👷 Worker listening: topic={$topic}, group={$conf['group']}\n";

            if (pcntl_fork() === 0) {
                $worker = new Worker($options, $conf['group'], [$topic]);
                $worker->registerHandlers($handlersPath);
                $worker->start();
                exit;
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

            $meta = $attrs[0]->newInstance();
            $map[$meta->topic] = ['group' => $meta->group];
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
