<?php

namespace db;

class Db
{
    const CONFIG = [
        'host' => 'rdsso34etfxxg8w8p585k769-vpc-rw.mysql.rds.aliyuncs.com',
        'user' => 'admini',
        'password' => 'HuoYongXu123',
        'database' => 'yun-campusjob',
        'timeout' => 2
    ];

    private $db = "";

    public function __construct()
    {
        echo "init mysql --\n";
        $this->db = new \Swoole\Coroutine\MySQL();
        $result = $this->db->connect(self::CONFIG);
        if ($result === false) {
            throw new \Exception('DB error');
        }
    }

    /**
     * mysql 执行逻辑
     * @param $id
     * @param $username
     * @return bool
     */
    public function findAll($sender_uid, $receiver_uid, $msg_status = 0)
    {
        // connect
        $result = $this->db->connect(self::CONFIG);
        if ($result) {
            $stmt = $this->db->prepare("SELECT `sender_uid`,receiver_uid,msg_content,send_time,`msg_from`,`uuid`,`msg_status` FROM kzp_offline_msg WHERE `receiver_uid`= ? AND `sender_uid`= ? AND `msg_status`= ? ORDER BY create_time ASC");
            if ($stmt == false) {
            } else {
                $res = $stmt->execute(array($receiver_uid, $sender_uid, $msg_status));
                var_dump($res);
                return $res;
            }
        }
        return true;
    }

    //新增
    public function add($tmp)
    {
        if ($this->checkObject($tmp)) {
            $result = $this->db->connect(self::CONFIG);
            echo "---mysql connect: {$result} ---\n";

            if ($result) {
                $stmt = $this->db->prepare("INSERT INTO kzp_offline_msg (`uuid`,`receiver_uid`,`receiver_name`,`receiver_header`,`sender_uid`,`sender_name`,`sender_header`,`msg_content`,`msg_from`,`msg_status`,`send_time`,`create_time`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

                if ($stmt == false) {
                    var_dump($this->db->errno, $this->db->error);
                } else {
                    $res = $stmt->execute(array($tmp['uuid'], $tmp['receiver_uid'], $tmp['receiver_name'], $tmp['receiver_header'] ?? '', $tmp['sender_uid'], $tmp['sender_name'], $tmp['sender_header'] ?? '', $tmp['msg_content'], $tmp['msg_from'], 0, time(), time()));
                    var_dump($res);
                }
            }
        }
        return true;
    }

    private function checkObject($data)
    {
        if ($data) {
            foreach ($data as $k => $v) {
                if ($v === '') return false;
            }
            return true;
        }
        return false;
    }

    //更新数据库中  发给我的离线消息为已读
    public function update($data)
    {
        if ($this->checkObject($data)) {
            $result = $this->db->connect(self::CONFIG);
            echo "db update..";
            if ($result) {
                $stmt = $this->db->prepare("UPDATE kzp_offline_msg SET msg_status = ? , modify_time = ? where sender_uid = ? and receiver_uid = ? and msg_from = ?  and msg_status = 0");
                if ($stmt == false) {
                    var_dump($this->db->errno, $this->db->error);
                } else {
                    //更新的为发给我的未读消息
                    $res = $stmt->execute(array($data['msg_status'], time(), $data['receiver_uid'], $data['sender_uid'], $data['msg_to']));
                    var_dump($res);
                }
            }
        }
        return true;
    }
}
