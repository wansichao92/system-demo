<?php
declare (strict_types = 1);

namespace app;

use think\App;
use think\exception\ValidateException;
use think\Validate;
use think\facade\Db;
use think\facade\View;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    /**
     * 初始化
     */
    protected function initialize()
    {
        // 验证登入
        if (!session('?uid')) 
            return redirect('/publics/login.html')->send();
        
        // 保存操作日志
        autoInseruserLog($this->pageTitle ?? '页面标题');

        // 验证权限
        $name = app('request')->controller().'/'.app('request')->action();
        if (!authcheck($name, session('uid'))) 
            mtReturn(300, session('uid').'很抱歉，此项操作您没有权限！', $this->redirectUrl);
    }

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }
    
    /**
     * model列表显示属性及搜索属性
     * @access protected
     */
    protected function _befor_index()
    {
        $model = getDataById('model', $this->modelId);
        
        //排序处理
        if ($model['field_sort']) {
            if ($this->process_id>0) {
                $this->_join_sort($model['field_sort']);
            } else {
                $this->_sort = $model['field_sort'].",".$this->_sort;
            }
        }
        
        $this->search_ist($model['search_list']);
        $grids = preg_split('/[;\r\n]+/s', trim($model['list_grid']));
        
        foreach ($grids as &$value) {
            
            // 字段:标题:链接
            $val = explode(':', $value);
            
            // 支持多个字段显示
            $field = explode(',', $val[0]);
            $value = array('field'=>$field, 'title'=>$val[1]);
            
            if (isset($val[2])) {
                // 链接信息
                $value['href'] = $val[2];
                // 搜索链接信息中的字段信息
                preg_replace_callback('/\[([a-z_]+)\]/', function($match) use(&$fields){$fields[]=$match[1];}, $value['href']);
            }
            
            if (isset($val[3])) {
                // 链接信息
                $value['auth'] = $val[3];
            }
            
            if (isset($val[4])) {
                // 需要更新字段值
                $value['attribute'] = $val[4];
            }
            
            if (strpos($val[1],'|')) {
                // 显示格式定义
                list($value['title'], $value['format']) = explode('|', $val[1]);
            }
            
            foreach ($field as $val) {
                $array = explode('|', $val);
                $fields[] = $array[0];
            }
        }
        
        $this->grids = $grids;
        $explain = $model['explain'] ?? '';
        
        //列表显示字段 用于datatables点击排序
        foreach ($grids as $listfield) {
            $field = str2arr($listfield['field'][0], '|');
            $lists = array('field'=>$field[0], 'title'=>$listfield['title']);
            $listfields[] = $lists;
        }
        
        $listfields[-1] = $this->process_id>0 ? array('field'=>'alarm') : null;
        $this->listfields = $listfields;

        session($this->dbName.'_fields', $grids);

        View::assign([
            'list_grids' => $grids, 
            'field'      => $field,
            'lists'      => $lists,
            'listfields' => $listfields,
            'explain'    => $explain,
        ]);
    }

    /**
     * 连表排序操作
     */
    protected function _join_sort($field_sort)
    {
        $model = Db::name($this->dbName);
        $field_sorts = str2arr($field_sort);
        
        $is_join = false;
        foreach ($field_sorts as $sorts) {
            $f_sorts = str2arr($sorts, " ");
            if (in_array($f_sorts[0], getDbFields($this->dbName))) {
                $is_join = true;
                $field_sort = str_replace($f_sorts[0], "b.".$f_sorts[0], $field_sort);
            }
        }
        
        if ($is_join) {
            $table = strtoupper($this->dbName);
            $this->_join = ' INNER JOIN __'.$table.'__ b ON a.work_id=b.id';
        }
        $this->_sort = $field_sort.",".$this->_sort;
    }

    /**
     * model设置搜索
     * @access protected
     * @param  string  $model   数据库
     * @param  int     $status  1不展示|0展示（默认）
     */
    protected function _search(int $status=0)
    {
        $map   = array();
        $value = '';
        
        if (input('post.')) {
            
            setSearchCookie(0, $this->process_id);
            
            foreach ($this->search_list as $key => $val) {
                //只显示搜索 但不在此处进行处理搜索
                if (isset($val['is_search']) && $val['is_search']=='-1') {
                    continue;
                }
                
                //别名搜索
                $val['name'] = $val['src_name'] ?? $val['name'];                
                $field = array_key_exists($val['name'],$this->fields) ? $this->fields[$val['name']] : $val;
                
                if (!input('param.'.$field['name']) || empty(input('param.'.$field['name']))) {
                    continue;
                } elseif (is_array(input('param.'.$field['name']))) {
                    $value = arr2str(input('param.'.$field['name']));
                } else {
                    $value = trim(input('param.'.$field['name']));
                }
                
                if (method_exists($this, '_join_search')) {
                    $this->_join_search($val['name'], $field);
                }
                
                $field['type'] = $field['type'] ?? '';
                
                switch ($field['type']) {
                    case 'string':
                        $map[] = [$field['name'], 'like', '%'.$value.'%'];
                        break;
                    case 'date':
                    case 'daterangetime':
                    case 'datetime':
                        $date = str2arr($value,' - ');
                        if (is_array($date)) {
                            $start = $date[0];
                            $end = $date[1];
                            $end = $start==$end ? strtotime("$end   +1   day")-1 : strtotime("$end   +1   day")-1;
                            $map[] = [$field['name'], "between", [strtotime($start),$end]];
                        } else {
                            //continue;
                            break;
                        }
                        break;
                    default:
                        if (isset($field['is_multiple'])) {
                            $map[] = [$field['name'], "in", $value];
                        } else {
                            $map[] = [$field['name'], "=", $value];
                        }
                        break;
                }
            }
        }
        if (input('post.') || $value) {
            
            if ($status==1) {
                $map[] = ['a.status', '=', 1];
            }
            
            return $map;
        }
    }
    
    protected function search_ist($search_list)
    {
        if ($search_list) {
            
            $grids = preg_split('/[;\r\n]+/s', trim($search_list));
            
            foreach ($grids as &$value) {
                
                // 字段:标题:链接
                $val = explode(':', $value);
                $value = array('name'=>$val[0], 'title'=>$val[1]);
                
                if (isset($val[2])) {
                    // 链接信息
                    $value['is_search'] = $val[2];
                }
                
                if (isset($val[3])) {
                    // 链接信息
                    $value['type'] = $val[3];
                }
                
                if (strpos($val[1], '|')) {
                    // 显示格式定义
                    $explode = explode('|', $val[1]);
                    if (count($explode)==2) {
                        list($value['title'], $value['type']) = $explode;
                    } elseif (count($explode)==3) {
                        list($value['title'], $value['type'], $value['src_name']) = $explode;
                    }
                }
            }
            
            $this->search_list = $grids;
            
            View::assign([
                'search_list' => $grids, 
            ]);
        }
    }

    /**
     * 列表页面
     * @access protected
     */
    protected function _list($dbName, $model, $map, $asc=false) 
    {
        //join链式操作
        if (!isset($this->_join) || !is_array($this->_join)) {
            $this->_join[] = 'notJoin';
            $this->_join[] = 'notJoin';
            $this->_join[] = 'notJoin';
        }
        //group链式操作
        $this->_group = $this->_group ?? null;
        
        //排序字段 默认为主键名
        $order = $sort = "";
        if (input('param.order')) {
            //前端界面点击排序
            $orders = input('param.order')[0];
            if ($this->checkbox==1) {
                //不能让$orders['column']-1为-1
                $fieldcolumn = $orders['column']-1<0 ? null : $this->listfields[$orders['column']-1]; 
            } else {
                $fieldcolumn = $orders['column']<0 ? null : $this->listfields[$orders['column']];
            }
            if ($this->listfields && isset($fieldcolumn) && in_array($fieldcolumn['field'], getDbFields($dbName))) {
                $order = $fieldcolumn['field'];
                $sort = $orders['dir'];
            }
        }
        if ($order=='') {
            $order = $model->getPk();
        }
        //排序方式默认按照倒序排列
        //接受 sost参数 0 表示倒序 非0都 表示正序
        if ($sort=='') {
            $sort = $asc ? 'asc' : 'desc';
        }
        $pageCurrent = input('param.start') ?? 1;

        //取得满足条件的记录数
        if (isset($this->_having)) {
            $counts = $model->alias('a')->join($this->_join[0],$this->_join[1],$this->_join[2])->field('a.id')->where($map)->group($this->_group)->having($this->_having)->select()->toArray();
            $count = count($counts);
        } elseif (isset($this->_group)) {
            $counts = $model->alias('a')->where($map)->group($this->_group)->field('a.id')->select()->toArray();
            $count = count($counts);
        } else {
            $this->_count_field = $this->_count_field ?? "a.id";
            $count = $model->alias('a')->join($this->_join[0],$this->_join[1],$this->_join[2])->where($map)->count($this->_count_field);
        }
        
        if ($count>0) {
            $numPerPage = input('param.length') ?? 15;
            $orderss = "a.".$order." ".$sort;
            $ordersort = isset($this->_sort) ? $orderss.",".$this->_sort : $orderss;
            $this->_field = $this->_field ?? '*';
            
            //having链式操作
            $this->_having = $this->_having ?? 'notHaving';
            $model->removeOption();
            $voList = $model->alias('a')->join($this->_join[0],$this->_join[1],$this->_join[2])->field($this->_field)->where($map)->group($this->_group)->having($this->_having)->order($ordersort);
            if ($numPerPage>0) {
                $voList = $voList->limit(intval($pageCurrent),intval($numPerPage));
            }
            $voList = $voList->select()->toArray();
            
            //保存执行SQL，用于导出CSV数据
            $sql = $model->getLastSql();
            setListSql($this->process_id, $sql);

            //列表排序显示
            $sortImg = $sort; //排序图标
            $sortAlt = $sort == 'desc' ? '升序排列' : '倒序排列'; //排序提示
            $sort = $sort == 'desc' ? 1 : 0; //排序方式
            
            if (method_exists($this, '_after_list_child')) {
                $voList = $this->_after_list_child($voList);
            }
            
            View::assign([
                'lists'       => $voList, 
                'before_list' => $voList,
            ]);
        }
        
        if (input('param.length') && input('param.draw')) {
            if (isset($voList)) {
                foreach ($voList as $data) {
                    if (strpos($data[0], 'ids[]')===false && $this->checkbox==1){
                        array_unshift($data,'<label class="mt-checkbox mt-checkbox-single mt-checkbox-outline"><input name="ids[]" type="checkbox" class="checkboxes" value="1"/><span></span></label>');
                    }
                    $records['data'][]=array_values($data);
                }
            } else {
                $records['data'] = array();
            }
            
            $records["draw"] = intval(input('param.draw'));
            $records["recordsTotal"] = $count;
            $records["recordsFiltered"] = $count;
            
            return $records;
        }
        
        return;
    }
    
    /**
     * 列表查询数据作相应处理
     * @access public
     * @param  $list
     * @return array
     */
    protected function _after_list($list, $before_list=array())
    {
        $listss = array();
        foreach ($list as $key => $data) {
            $lists = array();
            if ($this->checkbox==1) {
                if (isset($data['alarm']) && $data['alarm']!=0) {
                    $lists[] = '<label class="mt-checkbox mt-checkbox-single mt-checkbox-outline"><input name="ids[]" type="checkbox" class="checkboxes" value="'.$data['id'].'"><span></span></label><img src="/Public/images/vip1.PNG" style="width:30px;height:30px;">';
                } else {
                    $lists[] = '<label class="mt-checkbox mt-checkbox-single mt-checkbox-outline"><input name="ids[]" type="checkbox" class="checkboxes" value="'.$data['id'].'"><span></span></label>';
                }
            }
            foreach ($this->grids as $grid) {
                if ((isset($grid['auth']) && display($this->controller.'/'.$grid['auth'])) || empty($grid['auth'])) {
                    if ((isset($grid['auth']) && display($this->controller.'/'.$grid['auth'], isset($before_list[$key]))) || empty($grid['auth'])) {
                        $lists[] = get_list_field($data, $grid, $this->process_id, $before_list[$key] ?? null);
                    } else {
                        $lists[] = "";
                    }
                }
            }
            $listss[] = $lists;
        }
        
        return $listss;
    }

    /**
     * 删除操作
     */
    public function delete()
    {
        $model = $this->process_id ? Db::name($this->dbName) : $this->model;
        $id = input('param.id') ?? implode(',', input('param.ids'));
        if ($id) {
            $data['status'] = 0;
            $data['updatetime'] = time();
            $model->where('id', 'in', $id)->update($data);
            if (method_exists($this, '_befor_delete')) {
                $this->_befor_delete($id);
            }
            mtReturn(200, '数据操作成功', $this->redirectUrl);
        }
    }

    /**
     * 添加操作
     */
    public function add()
    {
        if (input('param.')) {
            
            //防止添加的数据缺少字段，导致查看详情报错
            $data = $this->fields;
            foreach ($data as $key => $val) {
                $data[$key] = input('param.')[$key] ?? '';
            }
            $data['uid']      = getuserid();
            $data['status']   = 1;
            $data['dateline'] = time();
            
            //数据添加前操作
            if (method_exists($this, '_befor_insert')) {
                $data = $this->_befor_insert($data);
            }
            
            if ($this->model->insert($data)) {
                $id = $this->model->getLastInsID();
                $data['id'] = $id;
                //保存Redis
                setRedis($this->dbName."_".$id, $data); 
                //数据添加后操作
                if (method_exists($this, '_after_add')) {
                    $datas = input('param.');
                    $datas['id']       = $id;
                    $datas['uid']      = getuserid();
                    $datas['status']   = 1;
                    $datas['dateline'] = time();
                    $this->_after_add($datas);
                }
                mtReturn(200, "数据添加成功", $this->redirectUrl);
            }
        }
        
        //页面加载前操作
        if (method_exists($this, '_befor_add')) {
            $this->_befor_add();
        }
        
        return view('add', [
            'smallTitle' => '添加数据'
        ]);
    }

    /**
     * 编辑操作
     */
    public function edit() 
    {
        if (input('post.')) {
            
            $data = input('param.');
            $data['uid']        = getuserid();
            $data['status']     = 1;
            $data['updatetime'] = time();
            
            //数据编辑前操作
            if (method_exists($this, '_befor_update')) {
                $data = $this->_befor_update($data);
            }
            
            if ($this->model->save($data)) {
                $id = $data['id'];
                //保存Redis
                $basic_data = getDataById($this->dbName, $id);
                if ($basic_data) {
                    $redis_data = array_merge($basic_data, $data);
                }
                setRedis($this->dbName."_".$id, $redis_data);
                //数据编辑后操作
                if (method_exists($this, '_after_edit')) {
                    $datas = input('param.');
                    $datas['id']         = $id;
                    $datas['uid']        = getuserid();
                    $datas['status']     = 1;
                    $datas['updatetime'] = time();
                    $this->_after_edit($datas);
                }
                mtReturn(200, "数据编辑成功", $this->redirectUrl);
            }
        }
        
        $id = input('param.'.$this->model->getPk(), 0);
        $vo = getDataById($this->dbName, $id);
        
        //页面加载前操作
        if (method_exists($this, '_befor_edit')) {
            $vo = $this->_befor_edit($id, $vo);
        }
        
        return view('edit', [
            'smallTitle' => '编辑数据',
            'id'         => $id,
            'data'       => $vo
        ]);
    }

    /**
     * 查看详情操作
     */
    public function view()
    {
        $id = input('param.'.$this->model->getPk(), 0);
        $vo = getDataById($this->dbName, $id);
        
        return view('view', [
            'data' => $vo
        ]);
    }

    /**
     * 数据导出
     */
    public function excel(){
        
        //名称
        $filename = $this->pageTitle.'_'.date('Y-m-d',time());
        
        //导出列
        $headArr  = array();
        $fieldArr = $this->listfields ?? session($this->dbName.'_fields');
        foreach ($fieldArr as $key=>$field) {
            $headArr[] = $field['title'];
        }
        
        //数据
        $list = session($this->dbName.'_list');
        foreach ($list as &$item) {
            if (input('param.process_id') || $this->checkbox==1) {
                //去除checkbox
                unset($item[0]);
            }
            foreach ($item as &$val) {
                //去除html格式
                $val = strip_tags($val);
            }
        }
        
        //记录到操作日志
        $log['action'] = $this->pageTitle.'_数据导出';
        $log['remark'] = json_encode($list, JSON_UNESCAPED_UNICODE);
        insertUserLog($log);
        
        //执行导出
        xlsout($filename, $headArr, $list);
    }

    /**
     * 列表数据中转
     */
    protected function _after_list_child($list)
    {
        $but_style = ['label-warning', 'label-primary', 'label-success', 'label-info', 'bg-green-jungle', 'bg-yellow-crusta', 'bg-grey-salt'];
        $list = parseDocumentList($list, $this->fields, $but_style);
        
        return $this->_after_list($list);
    }

    /**
     * 列表展示
     */
    public function index()
    {   
        // model列表显示属性及搜索属性
        if (method_exists($this, '_befor_index')) {
            $this->_befor_index();
        }
        
        // 列表筛选，status的值
        $map = $this->_search(1);
        
        // 过滤器
        if (method_exists($this, '_filter')) {
            $this->_filter($map);
        }
        
        // 列表数据展示
        if (!empty($this->model) && (input('param.length') && input('param.draw'))) { 
            $res = $this->_list($this->dbName, $this->model, $map); 
            session($this->dbName.'_list', $res['data']);
            //输出数据
            return json($res);
        }
        
        // 导出数据
        if (input('param.action')=='excel') {
            $this->excel();
            exit;
        }
        
        // 模版渲染
        return view('index', [
            'smallTitle' => '列表展示'
        ]);
    }
}
