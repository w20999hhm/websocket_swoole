<?php

require_once './redis/Predis.php';

use redis\Predis;

class Push_wss
{
    CONST HOST = "0.0.0.0";
    CONST PORT = 9189;
    CONST GID_PRE = 'G_';
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
        swoole_set_process_name('live_master_push');
    }

    public function onOpen($ws, $request)
    {
        echo "handshake success with fd:{$request->fd}\n";
    }

    public function onWorkerStart($server)
    {
        require './task/PushTask.php';
        require './util/Push_message.php';
    }

    public function onTask($serv, $taskId, $workerId, $data)
    {
        //分发 task 任务机制 ，让不同的任务， 走不同的逻辑
        $obj = new task\PushTask();
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
        $message = new util\Push_message();
        $message->receiverMessage($ws, $frame);
    }

    public function onFinish($serv, $taskId, $data)
    {
        echo "taskId:{$taskId} \n";
    }

    public function onClose($ws, $fd)
    {
        $taskData['method'] = 'exitOnline';
        $data['fd'] = $fd;
        $taskData['data'] = $data;
        usleep(3000);
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

new Push_wss();