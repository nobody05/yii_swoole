<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/3/7
 * Time: 上午10:41
 */

namespace tsingsun\swoole\bootstrap;

// use Swoole\Http\Request as SwooleRequest;
// use Swoole\Http\Response as SwooleResponse;

use tsingsun\swoole\server\Server;
use tsingsun\swoole\web\Application;
use yii\base\ExitException;
use Yii;
use yii\base\Event;

/**
 * Yii starter for swoole server
 * @package tsingsun\swoole\bootstrap
 */
class TcpApp extends BaseBootstrap
{

	/**
	 * 接收tcp数据
	 * [onReceive description]
	 * @param  [type] $server     [description]
	 * @param  [type] $fd         [description]
	 * @param  [type] $reactor_id [description]
	 * @param  [type] $data       [description]
	 * @return [type]             [description]
	 *
	 * 
	 */
	public function onReceive($server, $fd, $reactor_id, $data)
	{
		// @TODO 默认是json格式的数据穿过来的
		// 参考下workerman 的pack/unpack 搞成二进制的
		// 
		
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. ' fd: '. $fd. ' data: '. $data . PHP_EOL, FILE_APPEND);

		$this->parseFrameProtocol($data);

		$data = $this->onRequests(null, null);

		//@TODO 是否需要task任务

		$server->send($fd, $this->formatResponse($data));
	}

	/**
	 * 新的tcp连接
	 * [onConnect description]
	 * @param  [type] $server     [description]
	 * @param  [type] $fd         [description]
	 * @param  [type] $reactor_id [description]
	 * @return [type]             [description]
	 */
	public function onConnect($server, $fd, $reactor_id)
	{
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. 'fd: '. $fd . PHP_EOL, FILE_APPEND);

	}

	public function onClose($server, $fd, $reactor_id)
	{

	}

	/**
	 * @TODO需要增加对二进制协议的支持
     * 协议转为路由,如果协议不包含路由信息,则表示在启动类中执行请求业务处理
     * Frame协议为针对Yii MVC方式进行定义
     * @param Frame $frame
     */
    protected function parseFrameProtocol($frame)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__ . ' data: '. $frame->fd . $frame->data . PHP_EOL, FILE_APPEND);

        $data = json_decode($frame->data, true);

        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__ . ' data: '. var_export($data, true) . PHP_EOL, FILE_APPEND);

        if (json_last_error() == JSON_ERROR_NONE) {
            
            file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__ . ' data: '. var_export($data, true) . PHP_EOL, FILE_APPEND);

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


    /**
     * @param SwooleRequest $request
     * @param SwooleResponse $response
     * @throws \Exception
     * @throws \Throwable
     */
    public function handleRequest($request, $response)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        // echo __METHOD__. PHP_EOL;

        $app = new Application($this->appConfig);

        $app->getRequest()->setSwooleRequest($request);

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
     * 请求处理结束事件
     * @param Event $event
     */
    public function onHandleRequestEnd(Event $event)
    {
        file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. PHP_EOL, FILE_APPEND);

        /** @var Application $app */
        $app = $event->sender;
        if ($app->has('session', true)) {
            $app->getSession()->close();
        }
        if($app->state == -1){
            $app->getLog()->logger->flush(true);
        }
    }

}