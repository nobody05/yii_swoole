<?php
/**
 * Created by PhpStorm.
 * User: tsingsun
 * Date: 2017/12/9
 * Time: 上午11:49
 */

namespace nobody\swoole\server;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Swoole\Server as SwooleServer;


class WebSocketServer extends HttpServer
{
    public function onOpen(Server $server,$worker_id)
    {
        if($this->bootstrap){
            $this->bootstrap->onOpen($server,$worker_id);
        }
    }

    public function onMessage(Server $ws, Frame $frame){
        if($this->bootstrap){
            $this->bootstrap->onMessage($ws,$frame);
        }
    }

    public function onClose(Server $ws, $fd) {
        if($this->bootstrap){
            $this->bootstrap->onClose($ws,$fd);
        }
    }

    public function onTask(SwooleServer $ws, int $task_id, int $src_worker_id, $data)
    {
        if($this->bootstrap){
            $this->bootstrap->onTask($ws, $task_id, $src_worker_id, $data);
        }

    }

    public function onFinish(SwooleServer $ws, int $task_id, string $data)
    {
        if($this->bootstrap){
            $this->bootstrap->onFinish($ws, $task_id, $data);
        }

    }
}