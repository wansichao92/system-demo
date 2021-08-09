<?php
namespace app\model;

use think\Model;
use think\facade\Db;
use think\facade\Config;

class AuthGroup extends Model
{
    
    static public function getAuthExtendRulesOfExtend($uid, $extend_id, $type, $session)
    {
        $prefix = Config::get('database.connections.mysql.prefix');
        
        if (!$type) {
            return false;
        }
        if ($session) {
            $result = session($session);
        }
        if ($uid == session('uid') && !empty($result)) {
            return $result;
        }
        
        $result = Db::table($prefix.'auth_group_access g')
                    ->join($prefix.'auth_extend c','g.group_id=c.group_id')
                    ->where("g.uid='$uid' and extend_id=$extend_id and c.type='$type' and !isnull(extend_id)")
                    ->group('extend_id')
                    ->value('extend_id, group_concat(rules)');
        
        //保存用户所属用户组设置的所有权限规则id
        $ids = array();
        if (!$result) {
            return $result;
        }
        foreach ($result as $rules) {
            $ids = array_merge($ids, explode(',', trim($rules, ',')));
        }
        $ids = array_unique($ids);
        $map = array(
            'id'     => array('in',$ids),
            'type'   => $type,
            'status' => 1,
        );
        
        //读取用户组所有权限规则
        $rules = Db::table($prefix.'auth_rule')->where($map)->value('name',true);
        $result = array();
        foreach ($rules as $rule) {
            $result[] = strtolower($rule);
        }
        if ($uid==session('uid') && $session) {
            session($session, $result);
        }
        
        return $result;
    }

    /**
     * 获取某个用户组的用户列表  $path存在时，则获取部门用户
     * @param  $group_id
     * @param  string $path
     * @return mixed
     */
    static public function memberInGroup($group_id, $path="", $process_id=0, $pro_uid=0)
    {
        $prefix   = Config::get('database.connections.mysql.prefix');
        $l_table  = $prefix.'user';
        $r_table  = $prefix.'auth_group_access';
        $where    = 'a.group_id='.$group_id;
        $join     = "";
        if ($path) {
            //权限借调
            $field = "b.path,b.truename,b.`status`,b.state";
            $l_table = "LEFT JOIN (SELECT * FROM (SELECT a.luid AS username,concat(g.path, a.hr_group_id, ',') AS path,b.truename,b.`status`,b.state FROM ".$prefix."auth_group_lend a LEFT JOIN ".$prefix."user b ON a.luid = b.username LEFT JOIN ".$prefix."hr_group g ON a.hr_group_id = g.id WHERE a.extend_id=".$process_id." AND a.group_id=".$group_id." UNION ALL SELECT username,".$field." FROM ".$prefix."user b) ".$prefix."user)";
        }
        $join   = $l_table.' b ON a.uid=b.username';
        $where .= ' and status=1 and state=1';
        if ($path) {
            $where .= " and locate(b.path, '".$path."')";
        }
        if ($pro_uid) {
            //只取顶头上司uid 不包含本人
            $where .= " and uid not in (".$pro_uid.")";
        }
        $limit = $pro_uid ? 1 : true;
        
        $sql  = 'select uid from '.$r_table.' a '.$join.' where '.$where;
        $list = Db::query($sql);
        $list = (is_array($list) && $list) ? array_unique($list) : $list;
        
        return $list;
    }
}
