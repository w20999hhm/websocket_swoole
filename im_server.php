<?php

use db\Db;
use redis\Predis;
use task\Task;

require_once './redis/Predis.php';
require_once './task/Task.php';
require_once './db/Db.php';

class Im_server
{
    CONST HOST = "0.0.0.0";
    CONST PORT = 9188;
    CONST GID_PRE = 'G1_';
    CONST REDIS_KEY = Predis::CONFIG['live_im_key'];

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
        $this->ws->on("message", [$this, 'onMessage']);
        $this->ws->on("task", [$this, 'onTask']);
        $this->ws->on("finish", [$this, 'onFinish']);
        $this->ws->on("close", [$this, 'onClose']);
        $this->ws->start();
    }

    public function onStart($server)
    {
        swoole_set_process_name('live_im_master');
    }

    public function onOpen($ws, $request)
    {
        //TODO:
        //当客户端代码决定打开WebSocket时，它会联系HTTP服务器以获取授权“ticket”。
        //服务器生成此ticket。 它通常包含一些诸如 用户/帐户ID、请求ticket的客户端的IP地址，时间戳以及其他任何可能需要的内部数据。
        //服务器存储此ticket（即在数据库或缓存中），并将其返回给客户端。
        //客户端打开WebSocket连接，并作为初始握手的一部分发送此“ticket”。
        //然后，服务器可以比较此ticket，检查源IP，验证ticket尚未重新使用且未过期，并执行任何其他类型的权限检查。 如果一切顺利，则允许WebSocket连接。
        echo "handshake success with fd:{$request->fd}\n";
    }

    public function onTask($serv, $taskId, $workerId, $data)
    {
        //分发 task 任务机制 ，让不同的任务， 走不同的逻辑
        $obj = new Task();
        $method = $data['method'];
        $flag = $obj->$method($data['data'] ?? '', $serv);
        return $flag;
    }

    public function onMessage($ws, $frame)
    {
        $data = $frame->data;
        if ($data == 'HeartBeat...') {
            $ws->push($frame->fd, "PongPong...");
            return false;
        }

        $data = json_decode($frame->data, true);
        $gid = self::GID_PRE;
        $group_id = $data['group_id'] ?? '';

        if ($data['flag'] == 'init') {
            //判斷用戶身份
            //用户刚连接的时候初始化，每个用户登录时记录该用户对应的fd
            $key = $gid . $data['sender_uid'] . '_' . $data['msg_from'];
            $data = [
                "fd" => $frame->fd,
                "group_id" => $group_id,
                "key" => $key
            ];
            Predis::getInstance()->hSet(self::REDIS_KEY, $key, json_encode($data));
        } else if ($data['flag'] == 'area') {
            //区域广播
            if (!empty($data['group_id'])) {
                $taskData['method'] = 'pushLive';
                $data['redis_key'] = self::REDIS_KEY;
                $taskData['data'] = $data;
                $ws->task($taskData);
            }
        } else if ($data['flag'] == 'all') {
            $taskData['method'] = 'pushLive';
            $data['redis_key'] = self::REDIS_KEY;
            $taskData['data'] = $data;
            $ws->task($taskData);
        } else if ($data['flag'] == 'msg') {
            $to_key = $gid . $data['receiver_uid'] . '_' . $data['msg_to'];
            $to_data = Predis::getInstance()->hGet(self::REDIS_KEY, $to_key);
            if ($to_data) {
                $data['msg_status'] = 1;
            }
            //判断是否为聊天消息
            $business_type = $data['business_type'] ?? '';
            try {
                if ($business_type) {
                    //"message"  发送消息
                    if ($business_type === 'message') {
                        //region 消息数据组装 保存消息
                        $db = new Db();
                        $db->add($data);
                        //endregion
                    }
                    //  "read"   已读消息
                    if ($business_type === 'read') {
                        //更新数据库中  发给我的离线消息为已读
                        $data['msg_status'] = 1;
                        $db = new Db();
                        $db->update($data);
                    }
                }
            } catch (\Exception $e) {
                $ws->push($frame->fd, $e->getMessage());
                return false;
            }
            //在线 发送消息
            if ($to_data) {
                //将消息发送给对方
                $re = json_encode($data);
                $to_data = json_decode($to_data, true);
                $ws->push($to_data['fd'], $re);

                //通知发送者 消息已读
                $taskData['method'] = 'sendMessage';
                $readData['fd'] = $frame->fd;
                $readData['msgData'] = ["business_type" => 'read', 'flag' => 'msg', 'receiver_uid' => $data['sender_uid'], 'sender_uid' => $data['receiver_uid']];
                $taskData['data'] = $readData;
                $ws->task($taskData);
            }
        }
    }

    public function onFinish($serv, $taskId, $data)
    {
        echo "taskId:{$taskId} \n";
    }

    public function onClose($ws, $fd)
    {
        $taskData['method'] = 'exit';
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

new Im_server();
