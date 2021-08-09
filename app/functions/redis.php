<?php
// redis操作函数

use think\cache\driver\Redis;
use think\facade\Db;

/**
 * 封装Redis->set()操作
 * @param   string   $name     缓存字段
 * @param   string   $value    缓存值
 * @param   int      $expire   过期时间
 */
function setRedis($name, $value, $expire=null){
    $redis = new Redis();
    $keys  = strtolower($name);
    return $redis->set($keys, $value, $expire);
}

/**
 * 封装Redis->delete()操作
 * @param   string   $name     缓存字段
 */
function delRedis($name){
    $redis = new Redis();
    $keys  = strtolower($name);
    return $redis->delete($keys);
}

/**
 * 更新缓存
 * @param   string   $table   表名
 * @param   string   $id      索引
 * @param   array    $data    要更新的数据
 */
function updateRedis($table, $id, $data){
    $basic_data = getRedis($table, $id);
    if ($basic_data) {
        $redis_data = array_merge($basic_data, $data);
    }
    setRedis($table."_".$id, $redis_data ?? $data);
}

/**
 * 清空redis
 */
function cleanRedis(){
    $redis = new Redis();
    $redis->flushDB();
}

/**
 * 封装Redis->get()操作
 * @param   string   $name     缓存字段
 * @param   string   $id       索引
 * @param   string   $field    字段
 */
function getRedis($name, $id="", $field=""){
    $redis = new Redis();
    $key   = $field ? $name."_".$field : $name;
    $key   = $id ? $key."_".$id : $key;
    $keys  = strtolower($key);
    $value = $redis->get($keys);
    
    // 值为空，重赋值
    if (!$value && $id) {
        if (function_exists('return'.$name)) {
            // 如果有对应的函数名，直接传参数拿到数据
            $value = call_user_func('return'.$name, $id);
        } else{
            $model = Db::name($name)->alias('a');
            if (!$field){
                $value = $model->getById($id);
            } else {
                $where = $field."='".$id."'";
                $value = $model->where($where)->find();
            }
        }
        if ($value) {
            setRedis($keys, $value);
        }
    }
    
	return $value;
}
