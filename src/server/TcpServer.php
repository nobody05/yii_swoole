<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/12/9
 * Time: 上午11:49
 */

namespace tsingsun\swoole\server;

// use Swoole\WebSocket\Frame;
// use Swoole\WebSocket\Server;

use Swoole\Coroutine;
// use Swoole\Http\Request as SwooleRequest;
// use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use yii\helpers\FileHelper;

class TcpServer extends Server
{
	public $index = '/index.php';

    public function init()
    {
        if (defined('WEBROOT')) {
            $this->root = WEBROOT;
        }
        parent::init();
    }

    public function onConnect(Server $server,$fd, $reactor_id)
    {
    	file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. ' fd: '. $fd . PHP_EOL, FILE_APPEND);


        if($this->bootstrap){
            $this->bootstrap->onConnect($server, $fd, $reactor_id);
        }
    }

    public function onReceive(Server $server, $fd, $reactor_id, $data){

    	file_put_contents(PROJECTROOT. '/runtime/logs/yiidebug.log', __METHOD__. ' fd: '. $fd. ' data: '. $data . PHP_EOL, FILE_APPEND);

        if($this->bootstrap){
            $this->bootstrap->onReceive($server, $fd, $reactor_id, $data);
        }
    }

    public function onClose(Server $server, $fd, $reactor_id) {
        if($this->bootstrap){
            $this->bootstrap->onClose($server, $fd, $reactor_id);
        }
    }
}