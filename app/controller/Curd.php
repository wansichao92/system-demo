<?php
namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;

class Curd extends BaseController
{
    /**
     * 创建器初始化设置
     */
    public function __construct()
    {
        // 定义变量
        $this->pageTitle   = "demo1";
        $this->model       = Db::name('curd');
        $this->dbName      = 'curd';
        $this->modelId     = 1;
        $this->fields      = getAttribute(1);
        $this->process_id  = 0;
        $this->checkbox    = 0;
        $this->redirectUrl = '/curd/index.html';
        $this->controller  = strtolower(app('request')->controller());
        $this->searchData  = unserialize(cookie('searchData'));

        // 输出变量
        View::assign([
            'pageTitle'  => $this->pageTitle,
            '__URL__'    => '/'.$this->controller,
            'controller' => $this->controller,
            'fields'     => $this->fields,
            'checkbox'   => $this->checkbox,
            'searchData' => $this->searchData
        ]);
        
        // 初始化
        parent::initialize();
    }
}
