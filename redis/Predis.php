<?php

namespace redis;

class Predis
{

    CONST CONFIG = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'out_time' => 120,
        'timeOut' => 5,
        'live_game_key' => 'live_game_key',
        'live_im_key' => 'live_im_key',
        'live_test_key' => 'live_test_key',
        'company' => 'company_list_key',
        'user' => 'user_list_key',
        'fd_key' => 'fd_key'
    ];
    const LOG_PATH = '/webser/www/danmu/tmp/';

    public $redis = "";

    private static $_instance = null;

    public static function getInstance()
    {
        if (empty(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    private function __construct()
    {
        $this->redis = new \Redis();
        $result = $this->redis->connect(self::CONFIG['host'], self::CONFIG['port'], self::CONFIG['timeOut']);
        if ($result === false) {
            throw new \Exception('redis connect error');
        }
    }

    public function set($key, $value, $time = 0)
    {
        if (!$key) {
            return '';
        }
        if (is_array($value)) {
            $value = json_encode($value);
        }

        if (!$time) {
            return $this->redis->set($key, $value);
        }

        return $this->redis->setex($key, $time, $value);
    }

    public function get($key)
    {
        if (!$key) {
            return '';
        }

        return $this->redis->get($key);
    }

    public function hVals($key)
    {
        return $this->redis->hVals($key);
    }

    public function hGet($key, $field)
    {
        return $this->redis->hGet($key, $field);
    }

    public function hSet($key, $field, $value)
    {
        return $this->redis->hSet($key, $field, $value);
    }

    public function hDel($key, $field)
    {
        return $this->redis->hDel($key, $field);
    }

    public function hKeys($key)
    {
        return $this->redis->hKeys($key);
    }

    public function __call($name, $arguments)
    {
        if (count($arguments) != 2) {
            return '';
        }
        $this->redis->$name($arguments[0], $arguments[1]);
    }
}