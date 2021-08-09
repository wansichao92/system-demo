<?php
namespace app\controller;

use think\facade\Db;

class Execute
{
    public function __construct()
    {
        //dd('这是一个执行类文件，测试！');
    }

    public static function admKaoqin(...$args)
    {
        /*echo 'Execute::admKaoqin，参数：';
        print_r($args);
        exit;*/
    }
	
	/*清空redis缓存*/
	public function cleanRedis()
    {
        cleanRedis();
    }
}
