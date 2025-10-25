<?php
namespace Muxtorov98\YiiKafka;

use yii\base\BootstrapInterface;

/**
 * Yii2 Kafka Worker
 *
 * @package muxtorov98/yii2-kafka
 * @author  Tulqin Muxtorov <tulqin484@gmail.com>
 * @license MIT
 * @link    https://github.com/muxtorov98/yii2-kafka
 */
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
