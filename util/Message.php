<?php
/**
 * Created by PhpStorm.
 * User: w2099
 * Date: 2020/2/17
 * Time: 22:10
 */

namespace util;

use redis\Predis;

class Message
{
    CONST GID_PRE = 'G_';

    public function receiverMessage($ws, $frame, $redis_key)
    {
        $data = json_decode($frame->data, true);

        $gid = self::GID_PRE;
        if (!empty($data['gid'])) $gid = 'G' . $data['gid'] . '_';

        $business_id = $data['business_id'] ?? '';
        $group_id = $data['group_id'] ?? '';

        if ($data['flag'] == 'init') {
            if (isset($data['sender_uid']) && isset($data['msg_from'])) {
                $key = $gid . $data['sender_uid'] . '_' . $data['msg_from'];
                $msgFrom = $data['msg_from'];

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
                    /*     $re_key = $gid . $business_id;*/
                    $data['business_id'] = $business_id;
                    /*                   Predis::getInstance()->hSet($redis_key, $re_key, json_encode($data));*/
                }
                Predis::getInstance()->hSet($redis_key, $key, json_encode($data));

                //获取在线用户KEY
                $taskData['data'] = [
                    'redis_key' => $redis_key,
                    'key_list' => $key_list,
                    'fd' => $data['fd'],
                    'msg_from' => $msgFrom
                ];
                $taskData['method'] = 'getListOnline';
                $ws->task($taskData);

                //广播上线
                $onlineTask['method'] = 'addOnline';
                $onlineTask['data'] = [
                    'redis_key' => $redis_key,
                    'value' => $data,
                    'msg_from' => $msgFrom
                ];
                $ws->task($onlineTask);
            }
        } else if ($data['flag'] == 'notice') {
            if (isset($data['receiver_uid']) && isset($data['msg_to'])) {
                //私发消息
                $key = $gid . $data['receiver_uid'] . '_' . $data['msg_to'];
                $value = Predis::getInstance()->hGet($redis_key, $key);
                if ($value) {
                    $value = json_decode($value, true);
                    $arr = $ws->connection_info($value['fd']);
                    if ($arr && isset($arr['websocket_status']) && $arr['websocket_status'] > 2) {
                        $ws->push($value['fd'], $frame->data);
                    }
                }
            }
        } else if ($data['flag'] == 'state') {
            //todo 面试是否忙碌状态先屏蔽
            /*
            $key = $gid . $data['sender_uid'] . '_' . $data['msg_from'];
            echo "[state]-------------key: {$key} ---------------\n";
            $value = Predis::getInstance()->hGet($redis_key, $key);
            $value = json_decode($value, true);
            $business_type = $data['business_type'] ?? '';


            //私信是面试状态修改
              if ($business_type && $business_type == '状态') {
                  $value['state'] = $data['business_txt'];
                  Predis::getInstance()->hSet($redis_key, $key, json_encode($value));

                  //重新获取在线用户及状态
                  $taskData['method'] = 'toAll';
                  $data['redis_key'] = $redis_key;
                  $taskData['data'] = $value;
                  //获取在线用户KEY
                  $ws->task($taskData);
              }
            */
        } else if ($data['flag'] == 'msg') {
            if (isset($data['receiver_uid']) && isset($data['msg_to'])) {
                $to_key = $gid . $data['receiver_uid'] . '_' . $data['msg_to'];
                $to_data = Predis::getInstance()->hGet($redis_key, $to_key);
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
                            //更新数据库中 离线消息 为已读
                            $db = new Db();
                            $db->update($data);
                        }
                        //  "msg_list" 消息列表
                        if ($business_type === 'msg_list') {
                            $db = new Db();
                            //查找对方的未读消息
                            $list = $db->findAll($data['receiver_uid'], $data['sender_uid']);

                            if (!empty($list)) {
                                //将未读消息设置为已读
                                $updateData['sender_uid'] = $data['receiver_uid'];
                                $updateData['receiver_uid'] = $data['sender_uid'];
                                $updateData['msg_from'] = $data['msg_to'];
                                $updateData['msg_status'] = 1;
                                $db->update($updateData);

                                //将未读消息发送到客户端
                                $temp_data['fd'] = $frame->fd;
                                $temp_data['list'] = $list;
                                $taskData['data'] = $temp_data;
                                $taskData['method'] = 'messageList';
                                $ws->task($taskData);
                            }
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
        } else if ($data['flag'] == 'area') {
            //区域广播
            if (isset($data['group_id']) && !empty($data['group_id'])) {
                $taskData['method'] = 'pushLive';
                $data['redis_key'] = $redis_key;
                $taskData['data'] = $data;
                $ws->task($taskData);
            }
        } else if ($data['flag'] == 'all') {
            $taskData['method'] = 'pushLive';
            $data['redis_key'] = $redis_key;
            $taskData['data'] = $data;
            $ws->task($taskData);
        }

    }
}