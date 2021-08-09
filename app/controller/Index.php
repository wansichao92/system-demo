<?php
namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Session;

class Index extends BaseController
{
    /**
     * 构造方法
     */
    public function __construct()
    {
        $this->pageTitle   = "首页";
        $this->redirectUrl = '/Index/index.html';
        parent::initialize();
    }

    /**
     * 首页
     */
    public function index()
    {
        //echo app()->getRuntimePath() . 'schema' . DIRECTORY_SEPARATOR; exit;
        /*setRedis('name411','lixuemin123111');
        echo getRedis('name411') ?? '没有了';
        delRedis('name411');
        echo getRedis('name411') ?? '没有了';
        exit;*/
        return View();
    }
}
