<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/12/9
 * Time: 上午11:27
 */

namespace nobody\swoole\bootstrap;

use Yii;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use nobody\swoole\web\Application;
use yii\base\Event;
use yii\web\ForbiddenHttpException;


use nobody\swoole\di\Container;
use nobody\swoole\di\ContainerDecorator;
use nobody\swoole\di\Context;

// use nobody\swoole\di\Container;
// use nobody\swoole\di\ContainerDecorator;
// use nobody\swoole\di\Context;


class WebSocketApp extends WebApp
{
    /**
     * @var string current message route
     */
    private $dataRoute;
    /**
     * @var mixed current message content
     */
    private $dataContent;
    /**
     * @var array the format use fd=>controller
     */
    private $routes;
    /**
     * @var callable handle return data
     */
    public $formatData;

    /**
     * 客户端连接
     * @param Server $ws
     * @param Request $request
     */
    public function onOpen(Server $ws, Request $request)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        $pathInfo = $request->server['path_info'];
        $this->dataRoute = $request->server['path_info'] . '/open';
        
        // @TODO 需要修改或者定制化
        $data = ['code'=>'200', 'message'=>'ok'];
        $ws->push($request->fd, $this->formatResponse($data));

        if ($data instanceof \Throwable) {
            $ws->close($request->fd);
        }
        $this->routes[$request->fd] = $pathInfo;
    }

    /**
     * websocket 增加onRequest 监听http请求
     * 只要设置这个回调就能监听了
     * [onRequest description]
     * @return [type] [description]
     */
    public function onRequest($request, $response)
    {
        $this->initRequest($request);
        if (COROUTINE_ENV) {
            //协程环境每次都初始化容器,以做协程隔离
            Yii::$context->setContainer(new Container());
        }
        
        $result = $this->handlerHttpRequest($request, $response);

        if (COROUTINE_ENV) {
            Yii::$context->removeCurrentCoroutineData();
        }

        return $result;
    }

    /**
     * 只用来处理websocket 下的http请求
     * 流程与处理正常http请求是一样的参见WebApp.php 
     * [handlerHttpRequest description]
     * @param  [type] $request  [description]
     * @param  [type] $response [description]
     * @return [type]           [description]
     */
    public function handlerHttpRequest($request, $response)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        $app = new Application($this->appConfig);

        $app->getRequest()->setSwooleRequest($request);
        // echo 'setSwooleRequest after'. PHP_EOL;

        $app->getResponse()->setSwooleResponse($response);
        $app->on(Application::EVENT_AFTER_RUN, [$this, 'onHandleRequestEnd']);

        try {

            $app->beforeRun();

            $app->state = Application::STATE_BEFORE_REQUEST;
            $app->trigger(Application::EVENT_BEFORE_REQUEST);

            $this->state = Application::STATE_HANDLING_REQUEST;
            $response = $app->handleRequest($app->getRequest());

            $app->state = Application::STATE_AFTER_REQUEST;
            $app->trigger(Application::EVENT_AFTER_REQUEST);

            $app->state = Application::STATE_SENDING_RESPONSE;

            $response->send();

            $app->trigger(Application::EVENT_AFTER_RUN);

            $app->state = Application::STATE_END;

            return $response->exitStatus;

        } catch (ExitException $e) {
            $app->end($e->statusCode, isset($response) ? $response : null);
            $app->state = -1;
            return $e->statusCode;
        } catch (\Exception $exception) {
            $app->getErrorHandler()->handleException($exception);
            $app->state = -1;
            return false;
        } catch (\Throwable $throwable) {
            $app->getErrorHandler()->handleError($throwable->getCode(),$throwable->getMessage(),$throwable->getFile(),$throwable->getLine());
            return false;
        }
    }

    /**
     * 消息通讯接口
     * @param Server $ws
     * @param Frame $frame
     */
    public function onMessage(Server $ws, Frame $frame)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        $this->parseFrameProtocol($frame);
        // 需要异步执行的任务
        if (in_array($this->dataRoute, $this->tasks)) {
            
            $ws->task($frame);

            // 默认返回成功
            $ws->push($frame->fd, $this->formatResponse(['code'=>200, 'msg'=>'ok', 'action'=>$this->dataRoute]));

        } else {
            // 同步执行
            // 这个地方都传了null
            // 原方法onRequest跟swoole的onRequest重名了
            $data = $this->onRequests(null, null);

            // 程序中返回数组  format为json
            $ws->push($frame->fd, $this->formatResponse($data));
        }
    }

    /**
     * task异步任务
     * [onTask description]
     * @param  [type] $ws        [description]
     * @param  [type] $task_id   [description]
     * @param  [type] $worker_id [description]
     * @param  [type] $data      [description]
     * @return [type]            [description]
     */
    public function onTask($ws, $task_id, $worker_id, $frame)
    {
        $this->server->log->info(__METHOD__);

        $this->parseFrameProtocol($frame);

        // 这个地方都传了null
        // 原方法onRequest跟swoole的onRequest重名了
        $res = $this->onRequests(null, null);

        // 程序中返回数组  format为json
        // 测试
        // task任务不需要返回了
        // $ws->push($frame->fd, $this->formatResponse($res));

        return 'ok';
    }

    public function onFinish($ws, $task_id, $data)
    {
        // file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__ . PHP_EOL, FILE_APPEND);

    }

    public function onClose(Server $ws, $fd)
    {
        if (isset($this->routes[$fd])) {
            $this->dataRoute = $this->routes[$fd] . '/close';
            // $this->onRequest(null, null);
            unset($this->routes[$fd]);
        }
    }

    /**
     * 协议转为路由,如果协议不包含路由信息,则表示在启动类中执行请求业务处理
     * Frame协议为针对Yii MVC方式进行定义
     * @param Frame $frame
     */
    protected function parseFrameProtocol($frame)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__ . ' data: '. $frame->fd . $frame->data . PHP_EOL, FILE_APPEND);

        $data = json_decode($frame->data, true);

        if (json_last_error() == JSON_ERROR_NONE) {
            
            if (isset($data['route']) && isset($data['content'])) {
                $this->dataRoute = $data['route'];
                $this->dataContent = $data['content'];
                return;
            }
        }
        $this->dataRoute = $this->routes[$frame->fd] . '/message';
        $this->dataContent = $frame->data;

    }

    /**
     * @inheritdoc
     */
    public function handleRequest($request, $response)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        $app = new Application($this->appConfig);
        try {
            /**
             * request存在则是websocket下的http请求，swoole也是可以处理的
             */
            // $app->request->setSwooleRequest($request);

            // echo 'setSwooleRequest after'. PHP_EOL;

            // $app->request->setSwooleResponse($response);

            /*if ($request) {
                return parent::handleRequest($request, $response);
                // $app->getRequest()->setSwooleRequest($request);
            } else {*/
                $app->request->setPathInfo($this->dataRoute);
                
                // websocket 没有get/ post
                $app->request->setBodyParams($this->dataContent);
                $app->request->setQueryParams($this->dataContent);

                $app->on(Application::EVENT_AFTER_RUN, [$this, 'onHandleRequestEnd']);

    //            $app->beforeRun();
                $app->state = $app::STATE_BEFORE_REQUEST;
                $app->trigger($app::EVENT_BEFORE_REQUEST);

                $app->state = $app::STATE_HANDLING_REQUEST;
                $response = $app->handleRequest($app->getRequest());

                $app->state = $app::STATE_AFTER_REQUEST;
                $app->trigger($app::EVENT_AFTER_REQUEST);
                $app->state = $app::STATE_SENDING_RESPONSE;
                $app->trigger($app::EVENT_AFTER_RUN);

                // file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__ . '** '. $response->data . PHP_EOL, FILE_APPEND);

                return $response->data;
            // }
        } catch (ForbiddenHttpException $fe) {
            $app->getErrorHandler()->logException($fe);
            return $fe;
        } catch (\Exception $e) {
            $app->getErrorHandler()->logException($e);
            return $e;
        } catch (\Throwable $t) {
            $app->getErrorHandler()->logException($t);
            return $t;
        }
    }

    /**
     * 请求处理结束事件
     * @param Event $event
     */
    public function onHandleRequestEnd(Event $event)
    {
        /** @var Application $app */
        $app = $event->sender;
        if ($app->state == -1) {
            $app->getLog()->logger->flush(true);
        }
    }

    /**
     * @param mixed|ForbiddenHttpException|\Exception|\Throwable $data
     * @return mixed
     */
    public function formatResponse($data)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        $func = $this->formatData;
        if (is_callable($func)) {
            $result = $func($data);
            if (!is_string($result)) {
                $result = json_encode(['errors' => [['code' => '500', 'message' => 'the formatData call return value must be string']]]);
            }
            return $result;
        } elseif ($data instanceof \Throwable) {
            $result = ['errors' => [['code' => $data->getCode(), 'message' => $data->getMessage()]]];
        } else {
            $result = ['data' => $data];
        }

        return json_encode($result);
    }
}