<?php
/**
 * Created by PhpStorm.
 * User: w2099
 * Date: 2020/2/17
 * Time: 22:25
 */
require_once './redis/Predis.php';

use redis\Predis;


class Wss
{
    CONST HOST = "0.0.0.0";
    CONST PORT = 9189;
    CONST GID_PRE = 'G_';
    CONST REDIS_KEY = Predis::CONFIG['live_game_key'];

    public $ws = null;

    public function __construct()
    {
        $this->ws = new swoole_websocket_server(self::HOST, self::PORT);

        $this->ws->set([
            'worker_num' => 4,
            'task_worker_num' => 4
        ]);
        $this->ws->on("start", [$this, 'onStart']);
        $this->ws->on('open', [$this, 'onOpen']);
        $this->ws->on("workerstart", [$this, 'onWorkerStart']);
        $this->ws->on("message", [$this, 'onMessage']);
        $this->ws->on("task", [$this, 'onTask']);
        $this->ws->on("finish", [$this, 'onFinish']);
        $this->ws->on("close", [$this, 'onClose']);
        $this->ws->start();
    }

    public function onStart($server)
    {
        swoole_set_process_name('live_master');
    }

    public function onOpen($ws, $request)
    {
        echo "handshake success with fd:{$request->fd}\n";
    }

    public function onWorkerStart($server)
    {
        require './task/ListTask.php';
        require './util/Message.php';
    }

    public function onTask($serv, $taskId, $workerId, $data)
    {
        //分发 task 任务机制 ，让不同的任务， 走不同的逻辑
        $obj = new task\ListTask();
        $method = $data['method'];
        $flag = $obj->$method($data['data'] ?? '', $serv);
        return $flag;
    }

    public function onMessage($ws, $frame)
    {
        $data = $frame->data;
        if ($data == 'HeartBeat') {
            $ws->push($frame->fd, "PongPong");
            return false;
        }
        $message = new util\Message();
        $message->receiverMessage($ws, $frame, $this::REDIS_KEY);
    }

    public function onFinish($serv, $taskId, $data)
    {
        echo "taskId:{$taskId} \n";
    }

    public function onClose($ws, $fd)
    {
        $taskData['method'] = 'exitOnline';
        $data['redis_key'] = self::REDIS_KEY;
        $data['fd'] = $fd;
        $taskData['data'] = $data;
        $ws->task($taskData);
    }

    public function writeLog()
    {
        $datas = array_merge(['data' => date('Ymd H:i:s')], $_GET, $_POST, $_SERVER);
        $logs = '';
        foreach ($datas as $key => $value) {
            $logs .= $key . ':' . $value . '  ';
        }

        swoole_async_writefile(Predis::LOG_PATH . date("Ym") . '/' . date("d") . "_access.log", $logs . PHP_EOL, function ($fileName) {
            //todo
        }, FILE_APPEND);
    }
}

new Wss();