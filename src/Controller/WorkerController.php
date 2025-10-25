<?php
namespace Muxtorov98\YiiKafka\Controller;

use yii\console\Controller;
use Muxtorov98\YiiKafka\{KafkaOptions, Worker, KafkaHandlerInterface};
use Muxtorov98\YiiKafka\Attribute\KafkaChannel;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use ReflectionClass;

final class WorkerController extends Controller
{
    public $defaultAction = 'start';

    public function actionStart(): void
    {
        $options = KafkaOptions::fromArray(require \Yii::getAlias('@common/config/kafka.php'));

        $handlersPath = \Yii::getAlias('@common/kafka/handlers');
        $handlers = $this->discoverHandlers($handlersPath);

        echo "🚀 Kafka Worker starting...\n";

        foreach ($handlers as $topic => $config) {
            echo "👷 Worker listening: topic={$topic}, group={$config['group']}\n";

            if (pcntl_fork() === 0) {
                $w = new Worker(
                    $options,
                    $config['group'],
                    [$topic]
                );
                $w->registerHandlers($handlersPath);
                $w->start();
                exit;
            }
        }

        while (pcntl_wait($st) > 0);
    }

    private function discoverHandlers(string $dir): array
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        $map = [];

        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;

            $class = $this->classFromFile($file->getPathname());
            if (!$class || !class_exists($class)) continue;

            $ref = new ReflectionClass($class);
            $attrs = $ref->getAttributes(KafkaChannel::class);
            if (!$attrs) continue;

            $ch = $attrs[0]->newInstance();
            $map[$ch->topic] = [
                'group' => $ch->group,
            ];
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
