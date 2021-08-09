<?php
namespace app\controller;

use think\facade\Db;
use think\facade\View;

class Group
{
    /**
     * 构造方法
     */
    public function __construct()
    {
        // 定义变量
        $this->pageTitle   = "部门管理";
        $this->model       = Db::name('hr_group');
        $this->userModel   = Db::name('user');
        $this->controller  = strtolower(app('request')->controller());
        $this->redirectUrl = '/group/index.html';

        // 输出变量
        View::assign([
            'pageTitle' => $this->pageTitle,
            '__URL__'   => '/'.$this->controller
        ]);
        
        // 页面初始化
        initPage($this->pageTitle, $this->redirectUrl);
    }

    /**
     * 列表页面
     */
    public function index()
	{
        $list = $this->model->field('id,name,level,pid,path')->order('level')->select()->toArray();

		// "|__|__"符号
        $le[] = '';
        for ($i=1; $i<10; $i++) { 
			$le[$i] = '&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp'.$le[$i-1].'|__&nbsp'; 
		}
		
		for ($i=0; $i<count($list); $i++) { 
			// 样式
			for ($j=1; $j<10; $j++) { 
				if ($list[$i]['level']==$j) {
					$list[$i]['name'] = '<span class="w_bmxb">'.$le[$j].'</span>'.$list[$i]['name'];
				}
			}
			
			// 下拉图标
            $chlist = $this->model->where('pid', $list[$i]['id'])->count();
			if ($chlist) { 
				// 如果有子部门，它的部门前面加一个下拉图标
				$list[$i]['name'] = '<span class="bmxl">'.$list[$i]['name'].' <i class="w_i fa fa-caret-right"></i></span>';
			}

			// 统计部门人数
			$list[$i]['count'] = $this->userModel->where(['status'=>1, 'state'=>1, 'hr_group_id'=>$list[$i]['id']])->count();

            // 操作按钮
			$list[$i]['operation'] = '<a class="btn btn-sm btn-default" href="/hrgroup/edit/id/'.$list[$i]['id'].'.html"><e class="fa fa-edit"></e> 编辑</a>
                <a data-url="/hrgroup/del/id/'.$list[$i]['id'].'" data-type="get" class="btn btn-sm btn-default" data-width="600" data-toggle="doajax" data-confirm-msg="删除部门要确保此部门没有员工哦！"><i class="fa fa-times"></i> 删除</a>';

			// 数组排序
			$parent = $this->model->where('id', $list[$i]['id'])->value('pid');
			$key    = array_search($parent, array_column($list,'id'));
			array_splice($list, $key+1, 0, array($list[$i]));
			array_splice($list, $i+1, 1);
        }
        
        return view('index', [
            'list'       => $list,
            'smallTitle' => '部门列表'
        ]);
	}

    /**
     * 添加部门
     */
	public function add()
	{
		if (input('post.pid') && input('post.name')) {
			$groupInfo = $this->model->field('level,path')->where("id", input('post.pid'))->find();
			
			$data['name']  = input('post.name'); 				   //部门名称
			$data['pid']   = input('post.pid');					   //pid
			$data['level'] = $groupInfo['level']+1;  			   //部门等级
			$data['path']  = $groupInfo['path'].I('post.pid').','; //部门path
			
			$res = $this->model->insert($data) ? '新增成功' : '新增失败';
			mtReturn(200, $res, $this->redirectUrl);
		}else{
			return view('add', [
                'smallTitle' => '添加部门'
            ]);
		}
	}
    
    /**
     * 编辑部门
	 * 
	 * @explain 层级修改分4个步骤：
	 * 1.修改当前部门
	 * 2.修改当前部门的子部门
	 * 3.修改当前部门对应的员工path
	 * 4.修改当前部门的子部门对应的员工path
     */
	public function edit()
	{
		if (I('post.pid') && I('post.id')) {
			$userModel = M('user');
	    	$pid = I('post.pid');
			$id  = I('post.id');
			
			//上一级信息
			$parentInfo = $this->model->field('level,path')->where("id=".$pid)->find();
			
	    	$data['title'] = I('post.title'); 		       // 部门名称
			$data['pid']   = $pid; 						   // 父级部门
			$data['level'] = $parentInfo['level']+1;	   // 部门等级: 父级level+1
			$data['path']  = $parentInfo['path'].$pid.','; // path: 父级path+pid
			
			$subList = $this->model->field('id')->where(['path'=>['like','%,'.$id.',%']])->getfield('id', true);
			
			if (in_array($pid, $subList)) { 
				mtReturn(200, "不能选自己的子部门做父级部门!");
			} else {
				// 1.修改自己
				$this->model->where("id=".$id)->data($data)->save();
				// 2.修改子部门
				if ($this->model->create() && $subList) {
					// 查找子部门id
					$chlist = $this->model->field('id,level,pid,path')->where(['path'=>['like','%,'.$id.',%']])->order('id')->select();

					$update_level = $data['level'] - $this->model->where("id=".$id)->getfield('level'); //差值 $update_level
					$sql_save_level = "UPDATE erp_hr_group SET level  = CASE id "; 
					for ($i=0; $i<count($chlist); $i++) {
						$sql_save_level .= sprintf(" WHEN ". $chlist[$i]['id']. " THEN ". ($chlist[$i]['level']+$update_level)); 
						// 修改子部门path
						$this->model->where("id=".$chlist[$i]['id'])->data(['path'=>$parentPath.$chlist[$i]['pid'].','])->save();
					}
					$ids = implode(',', $subList); //把子部门id做修改条件
					$sql_save_level .= " END WHERE id IN ($ids)"; 
					// 修改子部门level
					$this->model->execute($sql_save_level);
				}
				
				//清空缓存
				$hr_group_m = new HrGroupModel;
				$hr_group_m->clearHrGroupS();

				// 3.修改员工表path
				$userModel->where("hr_group_id=".$id)->data(['path'=>$parentInfo['path'].$pid.','.$id.','])->save(); //员工表的path=部门表的path+部门id
				
				$where['path']        = array('like','%,'.$id.',%'); 
				$where['hr_group_id'] = array('NEQ', $id);
				$subUserList = $userModel->where($where)->getfield('hr_group_id', true); 
				
				// 4.修改子部门对应员工表的path
				if ($userModel->create() && $subUserList) {
					$subUserList = array_values(array_unique($subUserList)); //去重，重新排列
					$whereIds    = implode(',', $subUserList); //最终sql执行条件
					
					// 员工path=部门表path+自己的部门id
					$sql_save_userpath = "UPDATE erp_user SET path = CASE hr_group_id "; 
						for ($i=0; $i<count($subUserList); $i++) {
							$savePath      = $this->model->where('id='.$subUserList[$i])->getfield('path');
							$selfHrgroupId = $subUserList[$i].',';
							$sql_save_userpath .= sprintf(" WHEN ". $subUserList[$i]. " THEN "."'".$savePath.$selfHrgroupId."'"); 
						}
					$sql_save_userpath .= " END WHERE hr_group_id IN ($whereIds)"; 
					$userModel->execute($sql_save_userpath);
				}

				// 删除员工session_id,强制下线
				$outList = $userModel->field('session_id')->where(['path'=>['like','%,'.I('post.id').',%']])->select();
				foreach ($outList as $val) {
					$out = "/home/wwwroot/default/bterp/temp/sess_".$val['session_id'];
					unlink($out);
				}
				
				mtReturn(200, "修改成功", $this->redirectUrl);
			}
		}

		if (I('get.id')) {
			$groupInfo = $this->model->where('id='.I('get.id'))->field('pid,title')->find();
			$pTitle    = $this->model->where('id='.$groupInfo['pid'])->getField('title');
			
			$this->assign('id', I('get.id'));
	    	$this->assign('pid', $groupInfo['pid']);
	    	$this->assign('s_title', $groupInfo['title']);
	    	$this->assign('p_title', $pTitle);	

	    	return view('edit', [
	            'smallTitle' => '部门编辑'
	        ]);	
		}
	}
    
    /**
     * 删除部门
     */
    public function del()
    {
        if (input('param.id')) {
            $level = $this->model->where('id', input('param.id'))->value('level');
            $res   = $level ? $this->model->where('id', input('param.id'))->delete() : $this->model->where('id='.input('param.id').' or pid='.input('param.id'))->delete();
            $info  = $res ? '删除成功' : '删除失败';
			
			mtReturn(200, $info, $this->redirectUrl);
        }
	}
}
