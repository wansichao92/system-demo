<?php
namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Menu
{
    /**
     * 构造方法
     */
    public function __construct()
    {
        // 定义变量
        $this->pageTitle   = "员工管理";
        $this->model       = Db::name('menu');
        $this->controller  = strtolower(app('request')->controller());
        $this->redirectUrl = '/menu/index.html';

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
        if (input('param.name')) $map[] = ['name', 'like', '%'.input('param.name').'%'];
        
        $map[] = ['status', '=', 1]; 
        $map[] = ['link', '<>', ''];
        
        return $map;
    }
    
    /**
     * 列表页面
     */
    public function index()
    {
        $map = $this->select();

        $listCount = $this->model->where($map)->count('id');
        $this->model->removeOption('field');

        $field   = ['id','name','link','icon','sort','level','pid'];
        $records = page($listCount, $field);
        $list    = $this->model->where($map)->field($field)->order('id','asc')->select()->toArray();
        $this->model->removeOption('where');
        
        foreach ($list as $key => $val) {
            if (!$val['level']) { 
                //下拉图标
                $list[$key]['name'] = '<span>'.$val['name'].' <i class="w_i fa fa-caret-down"></i></span>';
            } else {
                //找到父id的key
                $_key = array_search($val['pid'], array_column($list,'id'));
                //插入到父id后面
                array_splice($list, $_key+1, 0, array($list[$key]));
				//删除原来数据
                array_splice($list, $key+1, 1);
            }
        }
        
        if ($list) {
            foreach ($list as $key => $val) {
                $list[$key]['name'] = $val['level']==1 ? '<span class="w_bmxb">&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp|__&nbsp|__&nbsp</span>'.$list[$key]['name'] : $list[$key]['name'];
                $addHtml = $val['level']==0 ? '<a data-url="/menu/sub/id/'.$val['id'].'" data-width="500" class="btn btn-sm btn-default" data-toggle="modal"><i class="fa fa-plus"></i> 添加子菜单</a>' : '';
                $list[$key][] = '<a data-url="/menu/edit/id/'.$val['id'].'" data-width="500" class="btn btn-sm btn-default" data-toggle="modal"><i class="fa fa-edit"></i> 编辑</a>
                <a data-url="/menu/del/id/'.$val['id'].'" data-type="get" class="btn btn-sm btn-default" data-width="600" data-toggle="doajax" data-confirm-msg="确定要删除吗？"><i class="fa fa-times"></i> 删除</a>'.$addHtml;
                $records["data"][] = array_values($list[$key]);
            }
        } else {
            $records["data"] = array();
        }
        
        if (input('param.length') || input('param.start')) {
            return json($records);
        }
        
        return view('index', [
            'smallTitle' => '菜单列表'
        ]);
    }

    /**
     * 添加页面
     */
    public function add()
    {
        if (input('post.')) {
            $data['name']  = input('post.name');
            $data['link']  = input('post.link');
            $data['icon']  = input('post.icon')!='' ? input('post.icon') : 'icon-folder';
            $data['sort']  = input('post.sort') ?? 0;
            $data['level'] = 0;
            $data['pid']   = 0;
            $res  = $this->model->insert($data);
            $info = $res ? '添加成功' : '添加失败';
            
            mtReturn(200, $info, $this->redirectUrl);
		} else {
            return view('add', [
                'smallTitle' => '添加一级菜单'
            ]);
        }
    }
    
    /**
     * 编辑方法
     */
    public function edit()
    {
		if (input('post.')) {
            $updata['name'] = input('post.name');
            $updata['link'] = input('post.link');
            $updata['icon'] = input('post.icon')!='' ? input('post.icon') : 'icon-folder';
            $updata['sort'] = input('post.sort') ?? 0;
            $res  = $this->model->where('id',input('param.id'))->update($updata);
            $info = $res ? '编辑成功' : '编辑失败';
            
            mtReturn(200, $info, $this->redirectUrl);
        }
        
        $field = ['id','name','link','icon','sort'];
        $list  = $this->model->where('id',input('param.id'))->field($field)->find();
        
        return view('edit', [
            'list'       => $list,
            'smallTitle' => '菜单编辑',
        ]);
    }
    
    /**
     * 添加子菜单
     */
    public function sub()
    {
        if (input('post.')) {
            $data['name']  = input('post.name');
            $data['link']  = input('post.link');
            $data['icon']  = input('post.icon')!='' ? input('post.icon') : 'icon-folder';
            $data['sort']  = input('post.sort') ?? 0;
            $data['level'] = 1;
            $data['pid']   = input('post.id');
            $res  = $this->model->insert($data);
            $info = $res ? '添加成功' : '添加失败';
            
            mtReturn(200, $info, $this->redirectUrl);
        }
        
        return view('sub', [
            'id'         => input('param.id'),
            'smallTitle' => '添加子菜单',
        ]);
    }

    /**
     * 删除方法
     */
    public function del()
    {
        if (input('param.id')) {
            $level = $this->model->where('id',input('param.id'))->value('level');
            $res = $level ? $this->model->where('id',input('param.id'))->delete() : $this->model->where('id',input('param.id'))->whereOr('pid',input('param.id'))->delete();
            $info = $res ? '删除成功' : '删除失败';
            
            mtReturn(200, $info, $this->redirectUrl);
        }
    }
}
