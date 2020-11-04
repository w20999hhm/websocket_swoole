<?php

namespace task;

use redis\Predis;

class Task
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
                    $serv->push($fd, json_encode($data));
                }
            } else {
                $serv->push($fd, json_encode($data));
            }
        }
    }

    public function getOnline($data, $serv)
    {
        $keys = Predis::getInstance()->hKeys($data['redis_key']);
        $values = Predis::getInstance()->hVals($data['redis_key']);

        foreach ($keys as $key) {
            $value = Predis::getInstance()->hGet($data['redis_key'], $key);
            $res = json_decode($value, true);
            $serv->push(intval($res['fd']), json_encode($values));
        }
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
                Predis::getInstance()->hDel($redis_key, $key);
                break;
            }
        }
        $this->getOnline($data, $serv);
    }

    public function exit($data, $serv)
    {
        $redis_key = $data['redis_key'];
        $vals = Predis::getInstance()->hVals($redis_key);
        $keys = Predis::getInstance()->hKeys($redis_key);
        if (empty($vals)) return false;

        foreach ($vals as $index => $val) {
            $val = json_decode($val, true);
            if ($val['fd'] == $data['fd']) {
                $key = $keys[$index];
                Predis::getInstance()->hDel($redis_key, $key);
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
                $serv->push($data['fd'], json_encode($item));
                usleep(100000);
            }
        }
    }

    public function sendMessage($data, $serv)
    {
        if ($data && $data['fd'] && $data['msgData']) {
            $serv->push($data['fd'], json_encode($data['msgData']));
        }
        return true;
    }

}