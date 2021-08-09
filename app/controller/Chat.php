<?php
namespace app\controller;

use think\facade\Db;
use think\facade\Session;

class Chat
{
    /**
     * fd关联绑定uid
     */
    public function bindUid()
    {
        if (input('post.set_fd')) {
            // 重置chat_fds
            if (input('post.set_fd')==1) {
                Db::name('user')->where(true)->update(['chat_fds'=>null]);
            }
            
            // 和工号绑定
            $fds = Db::name('user')->where('username', session('uid'))->value('chat_fds');
            $data['chat_fds'] = $fds ? $fds.'|'.input('post.set_fd') : input('post.set_fd');
            Db::name('user')->where('username', session('uid'))->update($data);
            
            setRedis('chat_name_'.input('post.set_fd'), session('userInfo.truename'));
            setRedis('chat_uid_'.input('post.set_fd'), session('uid'));
            setRedis('chat_headImg'.input('post.set_fd'), $this->headImg(session('userInfo.tx_id')));
            $res = getRedis('chat_name_'.input('post.set_fd'));
        } elseif (input('post.get_fd')) {
            $chat_name = getRedis('chat_name_'.input('post.get_fd'));
            $chat_uid  = getRedis('chat_uid_'.input('post.get_fd'));
            $chat_headImg = getRedis('chat_headImg'.input('post.get_fd'));
            $res = json([$chat_name, $chat_uid, $chat_headImg]);
        }

        return $res;
    }

    /**
     * 头像
     */
    protected function headImg($fileId)
    {
        return Db::name('file')->where('id',$fileId)->value('url') ?? false;
    }

    /**
     * 解除绑定
     */
    public function close()
    {
        if (input('get.fd')) {
            $uid = getRedis('chat_uid_'.input('get.fd'));
            $fds = Db::name('user')->where('username', $uid)->value('chat_fds');
            
            if ($fds==input('get.fd')) {
                $data['chat_fds'] = '';
            } else {
                $arr = explode('|', $fds);
                if ($arr[0]==input('get.fd')) {
                    $data['chat_fds'] = str_replace(input('get.fd').'|', '', $fds);
                } else {
                    $data['chat_fds'] = str_replace('|'.input('get.fd'), '', $fds);
                }
            }
            
            Db::name('user')->where('username', $uid)->update($data);
        }
    }

    /**
     * 获取当前在线名单
     */
    public function linkList()
    {
        if (input('post.links')) {
            $arr = explode('|', input('post.links'));
            foreach ($arr as $key=>$fd) {
                $uid = getRedis('chat_uid_'.$fd);
                if ($fd && $uid) {
                    $info = Db::name('user')
                    ->alias('a')
                    ->leftJoin('file b','a.tx_id = b.id')
                    ->leftJoin('hr_group c','a.hr_group_id = c.id')
                    ->where('username='.$uid)
                    ->field('a.truename,c.name,b.url')
                    ->find();

                    $res[$key]['fd']      = $fd;
                    $res[$key]['uid']     = $uid;
                    $res[$key]['name']    = $info['truename'];
                    $res[$key]['group']   = $info['name'];
                    $res[$key]['headImg'] = $info['url'];
                }
            }
            $res = assoc_unique($res, 'uid');
        }
        
        return json($res);
    }

    /**
     * 私聊
     */
    public function privateIn()
    {
        if (input('post.chatFrom') && input('post.chatTo')) {
            $chatTo   = Db::name('user')->where('username', input('post.chatTo'))->value('chat_fds');
            $chatFrom = Db::name('user')->where('username', input('post.chatFrom'))->value('chat_fds');
            $res = [$chatTo, $chatFrom];
            
            return json($res);
        }
    }
    
    /**
     * 保存聊天记录
     */
    public function msgLog()
    {
        if ($_POST) {
            
            $data['sender']      = session('uid');
            $data['talker']      = input('post.talker') ?? 0;
            $data['create_time'] = time();
            $data['msg_type']    = input('post.msg_type');
            $data['msg_text']    = input('post.msg_text');
            
            Db::name('chat_msg_log')->insert($data);
        }
    }

    /**
     * 重现聊天记录(私聊)
     */
    public function getPrivateMsgLog()
    {
        if ($_POST) {
            $time_7 = mktime(0, 0, 0, date('m'), date('d')-7, date('Y'))-1; //7天前的时间戳
            $map[]  = ['a.sender', 'in', session('uid').','.input('post.talker')];
            $map[]  = ['a.talker', 'in', session('uid').','.input('post.talker')];
            $map[]  = ['a.msg_type', '=', input('post.msg_type')];
            $map[]  = ['a.create_time', '>', $time_7];

            $msgLog = Db::name('chat_msg_log')
            ->alias('a')
            ->leftJoin('user b','a.sender = b.username')
            ->leftJoin('user c','a.talker = c.username')
            ->leftJoin('file d','b.tx_id = d.id')
            ->where($map)
            ->field(['sender','talker','FROM_UNIXTIME(a.create_time, "%Y-%m-%d %H:%s:%i") as create_time','msg_text','b.truename as sendername', 'c.truename as talkername', 'd.url'])
            ->select()
            ->toArray();
            
            return json($msgLog);
        }
    }

    /**
     * 重现聊天记录(公聊)
     */
    public function getPublicMsgLog()
    {
        if (input('post.msg_type')) {
            $time_7 = mktime(0, 0, 0, date('m'), date('d')-7, date('Y'))-1; //7天前的时间戳
            $map[]  = ['a.msg_type', '=', input('post.msg_type')];
            $map[]  = ['a.create_time', '>', $time_7];
            
            $msgLog = Db::name('chat_msg_log')
            ->alias('a')
            ->leftJoin('user b','a.sender = b.username')
            ->leftJoin('file c','b.tx_id = c.id')
            ->where($map)
            ->field(['sender','FROM_UNIXTIME(a.create_time, "%Y-%m-%d %H:%s:%i") as create_time','msg_text','b.truename as sendername','c.url'])
            ->select()
            ->toArray();
            
            return json($msgLog);
        }
    }
}