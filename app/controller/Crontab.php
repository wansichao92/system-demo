<?php
namespace app\controller;

use think\facade\Db;

class Crontab
{
    /**
     * 构造方法
     */
    public function __construct()
    {
        $this->pageTitle   = "定时任务器";
        $this->logUrl      = "crontab_log/demo.log";
    }

    public function index()
    {
        /**
         * demo.php
         * 每天凌晨0点整执行一次 
         * key  -- demo
         * 
         */
        if (input('param.key')=='demo') { 
            $logText = date('Y-m-d H:i:s',time()).' 执行了一次计划任务'."\n";
            file_put_contents($this->logUrl, $logText, FILE_APPEND);
        } else {
            return view('index', [
                'pageTitle'   => $this->pageTitle,
                'smallTitle'  => '功能说明',
            ]);
        }
    }
}
