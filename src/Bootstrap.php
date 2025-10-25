<?php
namespace Muxtorov98\YiiKafka;

use yii\base\BootstrapInterface;

final class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        if ($app instanceof \yii\console\Application) {
            $app->controllerMap['worker'] = [
                'class' => Controller\WorkerController::class,
            ];
        }
    }
}
