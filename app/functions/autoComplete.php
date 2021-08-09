<?php
// 自动完成函数库

/**
 * 请假超时，测试用
 * @param  $data
 * @return int
 */
function AutoTimeout($data){
    return 0;
}

/**
 * 计算请假时间 测试用
 * @param  $data
 * @return float|string
 */
function AutoSumQjday($data){
    if (isset($data['timefrom']) && isset($data['timeto'])) {
        $startTime = strlen($data['timefrom'])>10 ? strtotime($data['timefrom']) : $data['timefrom']; //开始时间
        $endTime   = strlen($data['timeto'])>10 ? strtotime($data['timeto']) : $data['timeto'];       //结束时间 
        $days = ($endTime-$startTime) / (3600*24);
        return $days;
    }
}
 