<?php
/**
 * 计划任务 -- demo
 * @param  string  $url   计划任务控制器
 * @param  array   $data  key--任务标识
 */

$url  = 'http://iframe.wsc92.cn/crontab/index';
$data = ["key"=>'demo'];

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $url);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($curl, CURLOPT_POST, 1);
curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
$info = curl_exec($curl);
return $info;
