<?php
    /**
     * 清空redis缓存
     */
    $redis = new Redis();      
    $redis->connect('127.0.0.1',6379);     
    $redis->flushDB();
?>