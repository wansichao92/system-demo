<?php
namespace app\controller;

use think\facade\Db;

class Search
{
    /**
     * 构造方法
     */
    public function __construct()
    {
        $this->pageTitle   = "站内关键词搜索";
        $this->userModel   = Db::name('user');
        $this->customModel = Db::name('customer');
        $this->redirectUrl = '/search/index.html';
        
        initPage($this->pageTitle, $this->redirectUrl);
    }
    
    /**
     * 列表页面
     */
    public function index()
    {
        $datas = [];
        
        if (input('post.key')) {
            $mapUser[] = ['username', '=', input('post.key')];
            $mapUser[] = ['truename', 'like', '%'.input('post.key').'%'];
            $mapUser[] = ['mobile', '=', input('post.key')];
            $mapCustom[] = ['cs_name', 'like', '%'.input('post.key').'%'];

            //公司员工
            $fieldUser = ['username','truename','sex','state','mobile','FROM_UNIXTIME(starttime, "%Y-%m-%d") as starttime'];
            $userList = $this->userModel->whereOr($mapUser)->field($fieldUser)->select()->toArray();
            if ($userList) {
                foreach ($userList as $val) {
                    $state           = $val['state']==1 ? '在职' : '离职';
                    $data['type']    = '公司员工';
                    $content         = '工号：'.$val['username'].'，姓名：'.$val['truename'].'，性别：'.$val['sex'].'，状态：'.$state.'，手机号码：'.$val['mobile'].'，入职时间：'.$val['starttime'];
                    $data['content'] = str_replace(input('post.key'), "<span style='color:#e62020'>".input('post.key')."</span>", $content);
                    $datas[] = $data;
                }
            }

            //公司客户（最多只展示50条数据）
            $fieldCustom = ['id','cs_name'];
            $customList = $this->customModel->whereOr($mapCustom)->field($fieldCustom)->limit(0,50)->select()->toArray();
            if ($customList) {
                foreach ($customList as $val) {
                    $data['type']    = '公司客户';
                    $content         = '客户id：'.$val['id'].'，公司名称：'.$val['cs_name'];
                    $data['content'] = str_replace(input('post.key'), "<span style='color:#e62020'>".input('post.key')."</span>", $content);
                    $datas[] = $data;
                }
            }
        }
        
        return view('index', [
            'pageTitle'   => $this->pageTitle,
            'datas'       => $datas,
            'keyValue'    => input('post.key') ?? '',
            'smallTitle'  => '搜索结果'.count($datas).'个'
        ]);
    }
}
