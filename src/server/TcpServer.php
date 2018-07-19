<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/12/9
 * Time: 上午11:49
 */

namespace nobody\swoole\server;

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

    public function onConnect(SwooleServer $server,$fd, $reactor_id)
    {
        if($this->bootstrap){
            $this->bootstrap->onConnect($server, $fd, $reactor_id);
        }
    }

    public function onReceive(SwooleServer $server, $fd, $reactor_id, $data)
    {
        if($this->bootstrap){
            $this->bootstrap->onReceive($server, $fd, $reactor_id, $data);
        }
    }

    public function onClose(SwooleServer $server, $fd, $reactor_id) {
        if($this->bootstrap){
            $this->bootstrap->onClose($server, $fd, $reactor_id);
        }
    }
}