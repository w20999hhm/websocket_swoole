<?php

namespace task;

use redis\Predis;

class ListTask
{

    public function pushLive($data, $serv)
    {
        $redis_key = $data['redis_key'];
        $clients = Predis::getInstance()->hKeys($redis_key);

        foreach ($clients as $index => $key) {
            //获取缓存数据
            $res = json_decode(Predis::getInstance()->hGet($redis_key, $key), true);
            $fd = intval($res['fd']);

            if (!empty($data['group_id'])) {
                if (!empty($res['group_id']) && $res['group_id'] == $data['group_id']) {
                    $arr = $serv->connection_info($fd);
                    if ($arr && isset($arr['websocket_status']) && $arr['websocket_status'] > 2) {
                        try {
                            $serv->push($fd, json_encode($data));
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }
            } else {
                $arr = $serv->connection_info($fd);
                if ($arr && isset($arr['websocket_status']) && $arr['websocket_status'] > 2) {
                    try {
                        $serv->push($fd, json_encode($data));
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }
    }

    public function getListOnline($data, $serv)
    {
        $keys = Predis::getInstance()->hKeys($data['redis_key']);
        $curr_fd = $data['fd'];
        $key_list = $data['key_list'] ?? '';

        $values = [];
        if (!empty($key_list)) {
            foreach ($key_list as $key) {
                if (in_array($key, $keys)) {
                    $value = Predis::getInstance()->hGet($data['redis_key'], $key);
                    $res = json_decode($value);
                    array_push($values, $res);
                }
            }
        } else {
            $values = Predis::getInstance()->hVals($data['redis_key']);
        }
        $arr = $serv->connection_info($curr_fd);
        if ($arr && isset($arr['websocket_status']) && $arr['websocket_status'] > 2) {
            $serv->push($curr_fd, json_encode($values));
        }
    }

    public function toAll($data, $serv)
    {
        $redis_key = $data['redis_key'];
        $vals = Predis::getInstance()->hVals($redis_key);
        if (empty($vals)) return false;
        $msg_from = $data['msg_from'] ?? '';

        foreach ($vals as $index => $val) {
            $res = json_decode($val, true);
            if (!empty($msg_from)) {
                if (strpos($res['key'], $msg_from) === false) {
                    $arr = $serv->connection_info($res['fd']);
                    if ($arr && isset($arr['websocket_status']) && $arr['websocket_status'] > 2) {
                        try {
                            $serv->push($res['fd'], json_encode([$data['value']]));
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }
            } else {
                $arr = $serv->connection_info($res['fd']);
                if ($arr && isset($arr['websocket_status']) && $arr['websocket_status'] > 2) {
                    try {
                        $serv->push($res['fd'], json_encode([$data['value']]));
                    } catch (\Exception $e) {
                        continue;
                    }
                }
            }
        }
    }

    public function addOnline($data, $serv)
    {
        $this->toAll($data, $serv);
    }

    public function exitOnline($data, $serv)
    {
        $redis_key = $data['redis_key'];
        $vals = Predis::getInstance()->hVals($redis_key);
        $keys = Predis::getInstance()->hKeys($redis_key);
        if (empty($vals)) return false;

        foreach ($vals as $index => $val) {
            $val = json_decode($val, true);
            if ($val['fd'] == $data['fd']) {
                $key = $keys[$index];
                $val['state'] = -1;
                Predis::getInstance()->hDel($redis_key, $key);
                $data['value'] = $val;
                $this->toAll($data, $serv);
                break;
            }
        }
    }

    public function messageList($data, $serv)
    {
        if (!empty($data['list'])) {
            foreach ($data['list'] as $item) {
                $item['business_type'] = 'message';
                $item['flag'] = 'msg';
                $arr = $serv->connection_info($data['fd']);
                if ($arr && isset($arr['websocket_status']) && $arr['websocket_status'] > 2) {
                    $serv->push($data['fd'], json_encode($item));
                    usleep(100000);
                }

            }
        }
    }

    public function sendMessage($data, $serv)
    {
        if ($data && $data['fd'] && $data['msgData']) {
            $arr = $serv->connection_info($data['fd']);
            if ($arr && isset($arr['websocket_status']) && $arr['websocket_status'] > 2) {
                $serv->push($data['fd'], json_encode($data['msgData']));
            }
        }
        return true;
    }

}