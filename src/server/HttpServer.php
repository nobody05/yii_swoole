<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/2/22
 * Time: 下午6:24
 */

namespace tsingsun\swoole\server;

use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use yii\helpers\FileHelper;

class HttpServer extends Server
{
    public $index = '/index.php';

    public function init()
    {
        if (defined('WEBROOT')) {
            $this->root = WEBROOT;
        }
        parent::init();
    }

    /**
     * @param SwooleRequest $request
     * @param SwooleResponse $response
     */
    public function onRequest($request, $response)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        $uri = $request->server['request_uri'];
        $file = $this->root . $uri;

        $pathinfo = pathinfo($file, PATHINFO_EXTENSION);

        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. $file .' **'. $request->server['request_uri']  . PHP_EOL, FILE_APPEND);

        if ($uri == '/' or $uri == $this->index or empty($pathinfo)) {
            //无指定扩展名
            $this->bootstrap->onRequest($request, $response);
        } elseif ($uri != '/' and $pathinfo != 'php' and is_file($file)) {
            // 非php文件, 最好使用nginx来输出
            $size = filesize($file);
            $response->header('Content-Type', FileHelper::getMimeTypeByExtension($file));
            $response->header('Content-Length', $size);
            //todo sendfile性能可能存在问题，用end来处理小文件。
            if ($size > 2097152) {
                //防止swoole warning
                $response->sendfile($file);
            } elseif ($size == 0) {
                $response->end();
            } else {
                $response->end(file_get_contents($file));
            }
        } elseif ($uri != '/' && $uri != $this->index) {
            //站点目录下的其他PHP文件
            $this->handleDynamic($file, $request, $response);
        }
    }

    public function onTask(SwooleServer $serv, int $task_id, int $src_worker_id, $data)
    {
        $result = $this->bootstrap->onTask($serv, $task_id, $src_worker_id, $data);
        return $result;
    }

    public function onFinish(SwooleServer $serv, int $task_id, string $data)
    {
        $this->bootstrap->onFinish($serv, $task_id, $data);
    }

    /**
     * 处理动态请求
     * @param SwooleRequest $request
     * @param SwooleResponse $response
     */
    protected function handleDynamic($file, SwooleRequest $request, SwooleResponse $response)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        if (is_file($file)) {
            $response->header('Content-Type', 'text/html');
            ob_start();
            try {
                include $file;
                $response->end(ob_get_contents());
            } catch (\Exception $e) {
                $response->status(500);
                $msg = $e->getMessage() . '!<br /><h1>Yii-swoole</h1>';
                $response->end($msg);
            }
            ob_end_clean();
        } else {
            $response->status(404);
            $response->end("Page Not Found({$request->server['request_uri']})！");
        }
    }


}