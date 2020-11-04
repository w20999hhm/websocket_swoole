<?php
/**
 * Created by PhpStorm.
 * User: w2099
 * Date: 2020/2/17
 * Time: 22:10
 */

namespace util;

use redis\Predis;

class Push_message
{
    CONST GID_PRE = 'G_';

    public function receiverMessage($ws, $frame)
    {
        $data = json_decode($frame->data, true);

        $gid = self::GID_PRE;
        if (!empty($data['gid'])) $gid = 'G' . $data['gid'] . '_';

        $business_id = $data['business_id'] ?? '';
        $group_id = $data['group_id'] ?? '';
        $msgFrom = $data['msg_from'];
        $msgTo = $data['msg_to'] ?? ($data['msg_from'] == 'company' ? 'user' : 'company');
        //自己所属队列的key
        $self_redis_key = Predis::CONFIG[$msgFrom];
        $other_redis_key = Predis::CONFIG[$msgTo];

        if ($data['flag'] == 'init') {
            if (isset($data['sender_uid']) && isset($data['msg_from'])) {
                $key = $gid . $data['sender_uid'] . '_' . $data['msg_from'];

                //需要获取状态的列表数据
                $key_list = $data['keys'] ?? [];
                $data = [
                    "fd" => $frame->fd,
                    "state" => 0,
                    "key" => $key
                ];

                if (!empty($group_id)) {
                    $data['group_id'] = $group_id;
                }
                if (!empty($business_id)) {
                    $data['business_id'] = $business_id;
                }
                Predis::getInstance()->hSet($self_redis_key, $key, json_encode($data));
                Predis::getInstance()->hSet(Predis::CONFIG['fd_key'], $frame->fd, $key);

                //获取在线用户KEY
                $taskData['data'] = [
                    'redis_key' => $other_redis_key,
                    'key_list' => $key_list,
                    'fd' => $data['fd'],
                    'msg_from' => $msgFrom
                ];
                $taskData['method'] = 'getListOnline';
                $ws->task($taskData);

                //广播上线
                usleep(3000);
                $onlineTask['method'] = 'addOnline';
                $onlineTask['data'] = [
                    'redis_key' => $other_redis_key,
                    'value' => $data,
                    'msg_from' => $msgFrom
                ];
                $ws->task($onlineTask);
            }
        } else if ($data['flag'] == 'notice') {
            if (isset($data['receiver_uid']) && isset($data['msg_to'])) {
                //私发消息
                $key = $gid . $data['receiver_uid'] . '_' . $data['msg_to'];
                $value = Predis::getInstance()->hGet($other_redis_key, $key);
                if ($value) {
                    $value = json_decode($value, true);
                    $arr = $ws->connection_info($value['fd']);
                    if ($arr && isset($arr['websocket_status']) && $arr['websocket_status'] > 2) {
                        $ws->push($value['fd'], $frame->data);
                    }
                }
            }
        } else if ($data['flag'] == 'area') {
            //区域广播
            if (isset($data['group_id']) && !empty($data['group_id'])) {
                $taskData['method'] = 'pushLive';
                $data['redis_key'] = ($self_redis_key == 'company' ? $self_redis_key : $other_redis_key);
                $taskData['data'] = $data;
                $ws->task($taskData);
            }
        } else if ($data['flag'] == 'all') {
            $taskData['method'] = 'pushLive';
            $data['redis_key'] = Predis::CONFIG['fd_key'];
            $taskData['data'] = $data;
            $ws->task($taskData);
        }
    }
}