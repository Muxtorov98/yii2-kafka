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
        $handlerDir = \Yii::getAlias('@common/handlers');

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($handlerDir));
        $handlers = [];

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;

            $class = $this->classFromFile($file->getPathname());
            if (!$class || !class_exists($class)) continue;

            $ref = new ReflectionClass($class);
            $attrs = $ref->getAttributes(KafkaChannel::class);

            if ($attrs) {
                $meta = $attrs[0]->newInstance();
                $handlers[] = [
                    'topic' => $meta->topic,
                    'group' => $meta->group,
                    'class' => $class,
                ];
            }
        }

        foreach ($handlers as $h) {
            echo "👷 Worker listening on queue: {$h['topic']} (group: {$h['group']})\n";

            if (pcntl_fork() === 0) {
                $worker = new Worker($options, $h['group'], [$h['class']]);
                $worker->run($h['topic']);
                exit;
            }
        }

        while (pcntl_wait($st) > 0);
    }

    private function classFromFile(string $path): ?string
    {
        $src = file_get_contents($path);
        preg_match('/namespace\s+([^;]+);/', $src, $ns);
        preg_match('/class\s+([^\s]+)/', $src, $cl);
        return ($ns && $cl) ? $ns[1] . '\\' . $cl[1] : null;
    }
}
