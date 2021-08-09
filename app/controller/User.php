<?php
namespace app\controller;

use think\facade\Db;
use think\facade\View;

class User
{
    /**
     * 构造方法
     */
    public function __construct()
    {
        // 定义变量
        $this->pageTitle   = "员工管理";
        $this->model       = Db::name('user');
        $this->controller  = strtolower(app('request')->controller());
        $this->redirectUrl = '/user/index.html';

        // 输出变量
        View::assign([
            'pageTitle' => $this->pageTitle,
            '__URL__'   => '/'.$this->controller
        ]);
        
        // 页面初始化
        initPage($this->pageTitle, $this->redirectUrl);
    }

    /**
     * 查询筛选项
     */
    public function select()
    {
        $map = array();
        if (input('param.username')) {
            $map[] = ['username', '=', input('param.username')];
        }
        if (input('param.sex')) {
            $map[] = ['sex', '=', input('param.sex')];
        }
        if (input('param.starttime')) {
            $dates = explode(" - ", input('param.starttime'));
            $time1 = $dates[0]." 00:00:00";
            $time2 = $dates[1]." 23:59:59";
            $map[] = ['starttime', 'between', [strtotime($time1),strtotime($time2)]];
        }
        if (input('param.state')) {
            $map[] = ['state', '=', input('param.state')];
        }
        
        return $map;
    }

    /**
     * 数据过滤
     */
    function filter(&$map) {
        if(maxpower()==false && !alllistPower()){
            $map[] = ['username', '=', session('uid')]; 
        }
    }
    
    /**
     * 列表页面
     */
    public function index()
    {
        // 页面数据导出
        if (input('param.action')=='excel') exit($this->excel());

        // 筛选条件
        $map = $this->select();
        
        //过滤器
        $this->filter($map);
        
        // 分页
        $listCount = $this->model->where($map)->count('id');
        $this->model->removeOption('field');
        $field = ['id','username','truename','sex','state','mobile','FROM_UNIXTIME(starttime, "%Y-%m-%d") as starttime'];
        $records = page($listCount, $field);
        
        // 数据
        $list = $this->model->order($records['column'],(string)$records['dir'])->where($map)->limit($records["start"],$records["length"])->field($field)->select()->toArray();
        if ($list) {
            foreach ($list as $key => $val) {
                $list[$key]['state'] = $val['state']==1 ? '<span class="label label-primary">在职</span>' : '<span class="label bg-red-thunderbird">离职</span>';
                if (display($this->controller.'/edit')) {
                    $list[$key]['but_edit'] = '<a data-url="/'.$this->controller.'/edit/username/'.$val['username'].'" data-width="500" class="btn btn-sm btn-default" data-toggle="modal"><i class="fa fa-edit"></i> 编辑</a>';
                }
                if (display($this->controller.'/del')) {
                    $list[$key]['but_del'] = '<a data-url="/'.$this->controller.'/del/username/'.$val['username'].'" data-type="get" class="btn btn-sm btn-default" data-width="600" data-toggle="doajax" data-confirm-msg="确定要删除吗？"><i class="fa fa-times"></i> 删除</a>';
                }
                $records["data"][] = array_values($list[$key]);
            }
            //把数据存入session，方便导出
            session('list', $list);
        } else {
            $records["data"] = array();
        }
        
        if (input('param.length') || input('param.start')) {
            return json($records);
        }
        
        return view('index', [
            'smallTitle' => '员工列表'
        ]);
    }

    /**
     * 删除方法
     */
    public function del()
    {
        if (input('param.username')) {
            $updata['state'] = 2;
			$res  = $this->model->where('username',input('param.username'))->update($updata);
            $info = $res ? '删除成功' : '删除失败';
            
            mtReturn(200, $info, $this->redirectUrl);
        }
    }
    
    /**
     * 编辑方法
     */
    public function edit()
    {
        if (input('post.')) {
            $updata['truename'] = input('post.truename');
            $updata['mobile']   = input('post.mobile');
            $res = $this->model->where('username',input('param.username'))->update($updata);
            $info = $res ? '编辑成功' : '编辑失败';
            
            mtReturn(200, $info, $this->redirectUrl);
        }
        
        $field = ['username','truename','mobile'];
        $list = $this->model->where('username',input('param.username'))->field($field)->find();
        
        return view('edit', [
            'smallTitle' => '员工信息编辑',
            'list'       => $list
        ]);
    }

    /**
     * 添加页面
     */
    public function add()
    {
        if (input('post.')) {
            $username = $this->model->max('username');
            $data['username']  = (int)$username + 1;
            $data['truename']  = input('post.truename');
            $data['mobile']    = input('post.mobile');
            $data['starttime'] = time();
            $data['sex']       = '男';
           
            $res = $this->model->insert($data);
            $info = $res ? '添加成功' : '添加失败';
            
            mtReturn(200, $info, $this->redirectUrl);
		} else {
            return view('add', [
                'smallTitle' => '添加员工'
            ]);
        }
    }

    /**
     * 数据导出
     */
    public function excel()
    {
        $headArr = ['序号','工号','姓名','性别','状态','手机','入职时间','编辑','删除'];
        
        $list = session('list');
        foreach ($list as $key => $val) { //去除html格式
            $list[$key]['state']    = strip_tags($list[$key]['state']);
            $list[$key]['but_edit'] = strip_tags($list[$key]['but_edit']);
            $list[$key]['but_del']  = strip_tags($list[$key]['but_del']);
        }
        
        //记录到操作日志
        $log['action'] = $this->pageTitle.'_数据导出';
        $log['remark'] = json_encode($list, JSON_UNESCAPED_UNICODE);
        insertUserLog($log);
        
        xlsout('员工列表', $headArr, $list);
    }

    /**
     * 数据导入
     */
    public function inxls()
    {
        if (input('param.')) {    
            if (input('post.filename_url')) {
                $filename = ".".input('param.filename_url');
                $value = xlsin($filename);
                
                //组装数据 表格从第2行开始
                for ($i=2; $i<count($value)+2; $i++) { 
                    $data['username']  = $value[$i]['A'];
                    $data['truename']  = $value[$i]['B'];
                    $data['sex']       = $value[$i]['C'];
                    $data['mobile']    = $value[$i]['D'];
                    $data['openid']    = $value[$i]['E'];
                    $data['state']     = 1;
                    $data['starttime'] = time();
                    $datas[] = $data;
                }
                
                if ($this->model->insertAll($datas)) {
                    $info = "导入成功".count($datas)."条数据。";
                    mtReturn(200, $info, $this->redirectUrl);
                }
            } else {
                $info = "请选择导入文件";
                mtReturn(200, $info, '/'.$this->controller.'/inxls.html');
            }
        }
        
        return view('inxls', [
            'smallTitle' => '上传员工信息（excel）'
        ]);
    }
}
