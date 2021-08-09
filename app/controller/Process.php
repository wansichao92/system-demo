<?php
namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Config;
use think\facade\Session;
use app\model\Task;

class Process extends BaseController
{
    /**
     * 创建器初始化设置
     */
    public function __construct() 
    {
        $process_id = input('param.process_id');
        if (!$process_id) {
            dd('缺少流程id，参数错误！');
        }

        $searchData = unserialize(cookie('searchData_'.$process_id));
        // 新进页面则重置待审核操作
        $searchData['folder'] = ""; 
        
        // Process流程信息
        $process = getProcessById($process_id);
        
        // 定义变量属性
        $this->process_id       = $process_id;
        $this->pageTitle        = $process['name'];
        $this->htmlTitle        = $process['html_name']!='' ? $process['html_name'] : '添加'; //add按钮名称
        $this->dbName           = trim($process['relatedtable']);
        $this->modelId          = $process['relatedtableId'];
        $this->controller       = strtolower(app('request')->controller());
        $this->process_is_indep = $process['is_indep'];
        $this->fields           = getAttribute($this->modelId);
        $this->checkbox         = 1;
        $this->searchData       = $searchData;
        $this->model            = Db::name('task_'.$this->process_id);
        $this->redirectUrl      = '/process/index/process_id/'.$process_id.'.html';
        $this->delexecute       = $process['delexecute'];
        
        // 输出变量
        View::assign([
            'process_id'       => $this->process_id, 
            'pageTitle'        => $this->pageTitle,
            'htmlTitle'        => $this->htmlTitle,
            'controller'       => $this->controller,
            '__URL__'          => '/'.$this->controller,
            'checkbox'         => $this->checkbox,
            'dbname'           => $this->dbName,
            'fields'           => $this->fields,
            'searchData'       => $this->searchData,
            'searchDatas'      => json_encode($this->searchData),
            'process_is_indep' => $this->process_is_indep,
            'model_array'      => Config::get('app.model_array')
        ]);
        
        // 初始化
        parent::initialize();
    }
    
    /**
     * 首页
     */
    public function index() 
    {
        // 批量审核操作
        if (input('post.ids')) {
            $this->audithandle();
        }
        
        if (method_exists($this, '_befor_index')) {
            $this->_befor_index();
        }
        
        // 列表部分字段不进行排序功能列：因为是拼接的数据，$this->dbName表中的字段排序是无效的，所以禁止
        $orderfalse   = array();
        $order_key    = $this->checkbox==1 ? 0 : -1;
        $orderfalse[] = $order_key;
        foreach ($this->grids as $key=>$grid) {
            if ((isset($grid['auth']) && display(strtolower(app('request')->controller()).'/'.$grid['auth'])) || empty($grid['auth'])) {
                $order_key++;
                if (!in_array($this->listfields[$key]['field'], getDbFields('task_'.$this->process_id))) {
                    $orderfalse[] = $order_key;
                }
            }
        }
        
        $map = $this->_search(1);
        
        if (method_exists($this, '_filter')) {
            $this->_filter($map);
        }
        
        //session('map', serialize($map));
        
        if (!empty($this->model) && (input('get.pre_process_id') || (input('param.length') && input('param.draw')))) {
            $res = $this->_list('task_'.$this->process_id, $this->model, $map);
            if ($res) {
                session($this->dbName.'_list', $res['data']);
                return json($res);
            }
        }
        
        // 模版渲染
        return view('index', [
            'orderfalse' => json_encode($orderfalse)
        ]);
    }

    /**
     * 搜索链表操作
     */
    protected function _join_search($fieldname, &$field)
    {
        if (in_array($fieldname, getDbFields($this->dbName))) {
            $this->_join[] = $this->dbName.' b';
            $this->_join[] = 'a.work_id = b.id';
            $this->_join[] = 'LEFT';
            $field['name'] = 'b.'.$field['name'];
        }
    }

    /**
     * 过滤器
     */
    public function _filter(&$map)
    {
        $map1 = $this->pre_process_work_id($map);
        if ($map1) {
            // 查看子流程相关信息，不判断是否具有查看相关子流程权限
            $map[] = $map1;
        } else {
            $folder = input('param.folder', "");
            
            //$auth_groups = userGroupList(); //userGroupList方法有问题！
            $auth_groups = '';

            if (method_exists($this, '_process_search')) {
                $this->_process_search($map);
            }

            if ($folder=="submit") {
                // 待审批任务
                $where = array('a.action_uids'=>array('like','%,'.session("uid").',%'), "_logic"=>"or");
                $where['_string'] = "FIND_IN_SET(action_role, '".$auth_groups."')";
                $where1 = array('a.uid'=>array('NEQ',session("uid")), "_complex"=>$where, "_logic"=>"and");
                $map[] = $where1;
            } else {
                if (maxpower()==false && !alllistPower()) {
                    $where = array();
                    if (deplistPower()) {
                        $dqp_subQuery = Db::name('user')->field('username')->where("path LIKE '%,".session("hr_group_id").",%' AND state=1")->buildSql();
                        $where = [
                            "a.uid"       => array('exp',' in '.$dqp_subQuery),
                            "a.dep"       => array('like','%,'.session("hr_group_id").',%'),
                            "related_dep" => array('like','%,'.session("hr_group_id").',%'),
                            "a.refer_dep" => array('like','%,'.session("hr_group_id").',%'),
                            "_logic"      => "or"
                        ];
                        
                        // 权限借调
                        $lend_list = Db::name('auth_group_lend')->where('luid='.session("uid").' AND extend_id='.$this->process_id.' AND group_id=90')->select()->toArray();
                        if ($lend_list) {
                            $lend_sql = "";
                            foreach ($lend_list as $lend) {
                                $lend_sql .= "OR a.dep LIKE '%,".$lend['hr_group_id'].",%' OR a.refer_dep LIKE '%,".$lend['hr_group_id'].",%' ";
                            }
                            $where['_string'] = trim($lend_sql, 'OR');
                        }
                    }
                    $where1 = [
                        "a.uid"        => array('EQ', session("uid")),
                        "a.uuid"       => array('EQ', session("uid")),
                        "related_uids" => array('like','%,'.session("uid").',%'),
                        "a.refer"      => array('like','%,'.session("uid").',%'),
                        "_logic"       => "or"
                    ];
                    $where1['_string'] = "FIND_IN_SET(action_role, '".$auth_groups."')";
                    
                    if ($where) {
                        $where1['_complex'] = $where;
                    }
                    $map[] = $where1;
                }
            }
        }
    }

    /**
     * 预处理工作id
     */
    public function pre_process_work_id(&$map)
    {
        // 流转流程信息
        $pre_process_id = input('get.pre_process_id');
        $work_id        = input('get.work_id');
        $processextra   = input('get.processextra'); // 额外参数
        if ($pre_process_id && $work_id) {
            // 继承字段 继承前流程相关字段值
            $pre_process_info = getDataById('process', $pre_process_id);
            $work_id_name     = $pre_process_info['relatedtable']."_id";
            $pre_work_data    = getDataById($pre_process_info['relatedtable'], $work_id);
            
            $pre_fields = array();
            foreach ($this->fields as $key=>$field) {
                // 继承上个流程的数据
                if ($field['type']=='preprocess' || $field['is_inherit']) {
                    if ($work_id_name==$field['name']) {
                        $pre_fields[$field['name']] = $pre_work_data['id'];
                    } else {
                        $pre_fields[$field['name']] = $pre_work_data[$field['name']];
                    }
                }
            }
            
            View::assign([
                'pre_fields'     => $pre_fields, 
                'work_id'        => $work_id, 
                'pre_process_id' => $pre_process_id, 
                'processextra'   => $processextra
            ]);
            
            if ($map) {
                $table = strtoupper($this->dbName);
                $this->_join =' LEFT JOIN __'.$table.'__ b ON a.work_id=b.id';
                return array("b.".$work_id_name=>array('EQ', $work_id),"_logic"=>"and");
            }
        }
    }

    /**
     * 指定流程附加筛选项
     */
    public function _process_search(&$map)
    {
        switch ($this->process_id) {
            case 3:
                if (input('param.wctime')) {
                    $wctime = explode(" - ", input('param.wctime'));
                    $time1 = $wctime[0]." 00:00:00";
                    $time2 = $wctime[1]." 23:59:59";
                    $where['dep'] = array("BETWEEN",array(strtotime($time1),strtotime($time2)));
                    $subQuery = Db::name('audit_8')->field('work_id')->where($where)->buildSql();
                    $map['a.work_id'] = array('exp',' in '.$subQuery."");
                }
                break;
        }
        // 部门筛选
        if (input('param.hr_group_id')) {
            $map[] = array('a.dep', 'like', '%'.','.input('param.hr_group_id').','.'%');
        }
    }
    
    /**
     * 列表查询数据作相应处理
     * @param  $list
     * @param  $before_list
     * @return array
     */
    public function _after_list($list, $before_list=array())
    {
        //整合任务实例与流程实例数据
        array_walk($list, 'mergeTaskData', $this->dbName);
        $before_list = $list;
        $list = parseDocumentList($list, $this->fields);
        $this->ListHandleModelFields($list);
        array_walk($list, 'addkey', array('process_id'=>$this->process_id));
        if (input('get.pre_process_id')) {
            return $list;
        } else {
            return parent::_after_list($list, $before_list);
        }
    }
    
    /**
     * 列表取关联model字段
     * @param  $list
     */
    public function ListHandleModelFields(&$list)
    {
        $work_ids = array_column($list, 'work_id');
        //$model = Db::name('task_'.$this->process_id);
        $taskFields = getDbFields('task_'.$this->process_id);
        $selects = array();
        foreach ($this->listfields as $listfields) {
            if(!array_key_exists($listfields['field'],$this->fields) && !in_array($listfields['field'],$taskFields)){
                foreach($this->fields as $key=>$field){
                    if($field['type']=='model'){
                        $model_id=$field['extra']['model_id'];
                        $models = logic($model_id);
                        if(in_array($listfields['field'],getDbFields($model_id))){
                            $selects[$model_id][]=$listfields['field'];
                        }
                    }
                }
            }
        }
        foreach($selects as $model_id=>$fields){
            $fields=array_merge(array('id'),$fields);
            $models=logic($model_id);
            $map=array();
            $map[$this->dbName."_id"]=array('IN',$work_ids);
            $datas=$models->where($map)->getField($this->dbName."_id,".arr2str($fields,','));
            $model_fields =   D('attribute')->getAttribute($model_id);
            $datas=parseDocumentList($datas,$model_fields);
            foreach($list as $k=>$data){
                $list[$k]=$datas[$data['work_id']] ? array_merge($datas[$data['work_id']],$data) : $data;
            }
        }
    }
    
    /**
     * 根据数据类型对存储数据进行处理
     * 统一方式
     * @param  $data
     * @return mixed
     */
    protected function handleData($data, $fields=array())
    {
        $fields = $fields ?? $this->fields;
        $dateTypes = array('date', 'datetime', 'daterange');
        foreach ($fields as $field) {
            if (in_array($field['type'], $dateTypes) && isset($data[$field['name']])) {
                $data[$field['name']] = strtotime($data[$field['name']]);
            } elseif (isset($data[$field['name']])) {
                switch ($field['type']) {
                    case 'checkbox':
                        $data[$field['name']] = implode(',', $data[$field['name']]);
                        break;
                }
            }
        }
        
        return $data;
    }

    /**
     * 插入数据前针对数据类型对数据进行处理
     * @param  $data
     * @return mixed
     */
    public function _befor_insert($data)
    {
        $data['audit']    = 1;
        $data['dep']      = session("userInfo.hr_group_id");
        $data['dep_path'] = session("userInfo.path"); //当前登录用户部门path

        $fieldData = array();
        foreach ($this->fields as $field) {
            if ($field['value']) {
                $field['value'] = handleAttributeValue($field['value']);
                $fieldData[$field['name']] = $field['value'];
            }
        }
        $data = array_merge($fieldData, $data);
        return $data;
    }
    
    public function model_func()
    {
        $modelInfo = getDataById('model', $this->modelId);
        if ($modelInfo['func']) {
            //整个模块数据通过函数进行处理
            $funcRe = call_user_func_array($modelInfo['func'], array(input('post.')));
            if ($funcRe['re']=='false'){
                mtReturn(300, $funcRe['info'], "");
            }
            return $funcRe;
        }
    }

    /**
     * 添加多条记录 -- 待完善
     */
    public function repeaterAddData($model)
    {
        $datas = input('post.');

        foreach ($this->fields as $field) {
            if ($field['type']=='repeater_model') {
                $repeater_field = $field['name'];
            }
        }

        $model_data = array();
        foreach ($datas[$repeater_field] as $repeater) {
            $model = logic($this->modelId);
            $data = array_merge($datas, $repeater);
            unset($data[$repeater_field]);
            $model_data[] = $data;
        }

        //开启事务
        Db::startTrans();
        
        //自动补全处理
        array_walk($model_data, array($this,'createData'), $this->modelId);
        foreach ($model_data as $data) {
            if (isset($data)) {
                if (method_exists($this, '_befor_insert')) {
                    $data = $this->_befor_insert($data);
                }
                if ($model->add($data)) {
                    $id = $model->getLastInsID();
                    
                    //保存Redis get即可调取数据表所有字段保存至REDIS
                    $data['id'] = $id;
                    getRedis($this->dbName, $id);
                    
                    //更新任务
                    TaskModel::addTask($data,$this->process_id);
                }
            }
        }

        Db::commit();

        mtReturn(200,'提交成功',Cookie('__forward__'));
    }

    /**
     * 添加操作
     */
    public function add()
    {
        if (method_exists($this, '_befor_add')) {
            $this->_befor_add();
        }
        
        //流程流转过来时处理
        $map = array();
        $this->pre_process_work_id($map);
        
        if (input('post.')) {
            
            $model = Db::name($this->dbName);
            
            //model表信息
            $modelInfo = getDataById('model', $this->modelId);
            
            //添加多条记录
            if ($modelInfo['is_repeater']==1) {
                $this->repeaterAddData($model);
            }
            
            //添加前操作
            if (method_exists($this, '_befor_insert')) {
                $data = $this->_befor_insert(input('post.'));
            }
            
            //主MODEL 执行function
            if (method_exists($this, 'model_func')) {
                $func_re = $this->model_func();
            }

            //开启事务
            Db::startTrans();
            
            //验证完成器
            $data = validateAuto($data);

            if ($model->strict(false)->insert($data)) {
                $id = $model->getLastInsID();
                
                //保存Redis
                $data['id'] = $id;
                setRedis($this->dbName."_".$id, $data);
                if (method_exists($this, '_after_add')) {
                    $datas = input('post.');
                    $datas['id'] = $id;
                    $this->_after_add($datas);
                }
                
                //更新任务
                Task::addTask($data, $this->process_id);
                
                //提交事务
                Db::commit();
                
                $re_mess = $func_re['info'] ? "新增成功！".$func_re['info'] : "新增成功!";
                
                mtReturn(200, $re_mess, $this->redirectUrl);
            }
        }
        
        return view('add', [
            'smallTitle'  => '添加数据'
        ]);
    }

    /**
     * 针对model类型字段 保存其关联表数据 -- 待完善
     * @param $data
     */
    public function _after_add($data)
    {
        //处理模型字段类型
        foreach ($this->fields as $key=>$field) {
            if ($field['type']=='model') {
                $table = $field['extra']['table'];
                $model_id = $field['extra']['model_id'];
                $model = logic($model_id);
                //去除数组空数据
                $validate_data = $data[$table];
                array_walk($validate_data, 'walk_array_filter', array());
                $this->addModelFieldTable($model_id, $data[$table], $data['id']);
            }
        }
        foreach ($this->fields as $key=>$field) {
            if ($field['type']=='model') {
                $model_id = $field['extra']['model_id'];
                $model = logic($model_id);
                $model->commit();
            }
        }
    }

    /**
     * 关联表批量保存
     * @param $table
     * @param $datas
     * @param $work_id
     */
    protected function addModelFieldTable($model_id,$datas,$work_id){
        $model=logic($model_id);
        $before_data=$datas;
        if(ACTION_NAME=="add")
            $models=array('model_id'=>$model_id,'work_id'=>$work_id);
        else
            $models=$model_id;

        if (in_array($this->dbName . "_id", $model->getDbFields()))
            array_walk($datas, 'addkey', array($this->dbName . "_id" => $work_id));
        else
            mtReturn(200,'关联模型字段相关模型表缺少字段：'.$this->dbName . "_id");

        $vo=getDataById($this->dbName,$work_id);                //基础表信息
        //基础表字段值继承至关联表
        foreach($this->fields as $key=>$field) {
            if ($field['is_inherit'] == 1 && in_array($field['name'], $model->getDbFields())) {
                array_walk($datas, 'addkey', array($field['name'] => $vo[$field['name']]));
            }
        }
        array_walk($datas,array($this,'createData'),$models);
        $datas=mergeArrayKeys($datas);

        if(array_filter($before_data) && $before_data && array_filter($datas) && $datas) {        //判断数据是否为空
            $model->addAll($datas);
        }
    }

    /**
     * 批量数据自动补全及验证
     * @param $value
     * @param $key
     * @param $model
     */
    protected function createData(&$value,$key,$models){
        $model_id=is_array($models) ? $models['model_id'] : $models;
        $model=logic($model_id);
        if(is_array($value)){
            $value=$model->create($value);
            ksort($value);
        }

        if($model->getError()) {
            if($models['work_id']){
                //删除主表记录
                $modelss = logic($this->modelId);
                $modelss->where("id=".$models['work_id'])->delete();
            }
            $this->priTableRollback();
            mtReturn(300,$model->getError(),"");
        }
    }

    /**
     * 审核时，如linktable类型字段为必填时未验证 取出本节点可编辑字段 用于必填字段未填时填充该字段空值（model验证需判断该字段有定义）
     * @param $value
     * @param $key
     * @param $fields
     */
    protected function buchongFields(&$value, $key, $fields)
    {
        $value = array_merge($fields, $value);
    }

    /**
     * 保证补充的字段有值 isset可以通过，为null时也将不验证
     * @param $value
     * @param $key
     */
    protected function nullChangeKong(&$value, $key)
    {
        $value = isset($value) ? $value : '';
    }

    /**
     * 附表数据更新失败，主表数据回滚
     */
    protected function priTableRollback(){
        $models = logic($this->modelId);
        $models->rollback();
        foreach($this->fields as $key=>$field){
            if($field['type']=='model'){
                $model_id=$field['extra']['model_id'];
                $model=logic($model_id);
                $model->rollback();
            }
        }
    }

    protected function handleArray(&$value,$key,$param){
        $value=$this->handleData($value,$param);
        //array_walk($value,array($this,'addkey'),array($this->dbName."_id"=>$data['id']));
    }

    /**
     * model类型字段 更新其关联表数据
     * @param $data
     */
    public function _after_edit($data){
        $nodeEditFields=$this->nodeEditFields($data['id'],true);                    //审核时，如linktable类型字段为必填时未验证 取出本节点可编辑字段 用于必填字段未填时填充该字段空值（model验证需判断该字段有定义）  by:weisisi 20171206
        array_walk($nodeEditFields,array($this,'nullChangeKong'));                  //保证补充的字段有值 isset可以通过，为null时也将不验证
        //处理模型字段类型
        foreach($this->fields as $key=>$field){
            if($field['type']=='model'){
                $table=$field['extra']['table'];
                $model_id=$field['extra']['model_id'];//var_dump($data);exit;
                $model=logic($model_id);
                //$model_fields=D('attribute')->getAttribute($field['extra']['model_id']);
                //array_walk($data[$table],array($this,'handleArray'),$model_fields);
                if($data[$table]){
                    //更新与保存数据分离
                    $tableData=array();
                    foreach($data[$table] as $datas){
                        $action=$datas['id'] ? 'update' : 'add';
                        if($action=="update"){
                            $datas['updatetime']=time();                //更新时间
                        }
                        $tableData[$action][]=$datas;
                    }
                    if($data[$table."_ids"]){
                        //原数据集ID 有值则可能存在删除操作
                        //原数据集ID
                        $before_ids=str2arr($data[$table."_ids"]);
                        //当前数据集ID
                        $ids = $tableData['update'] ? array_column($tableData['update'], 'id') : array();
                        //两者匹配差集 存在则有删除操作
                        $deleteIds=array_diff_assoc($before_ids,$ids);
                        if($deleteIds){
                            //删除操作
                            $where = 'id in('.arr2str($deleteIds).')';
                            $model->where($where)->delete();
                        }
                    }
                    if($tableData['update']){
                        //更新数据
                        $data[$table]=$tableData['update'];
                        array_walk($data[$table],array($this,'buchongFields'),$nodeEditFields);
                        array_walk($data[$table],array($this,'createData'),$model_id);
                        $sql="UPDATE ".C('DB_PREFIX').$table." SET ";
                        $sqls="";
                        $fields=array_keys($data[$table][0]);
                        foreach($data[$table] as $tdata){
                            $fields=array_unique(array_merge($fields,array_keys($tdata)));
                        }
                        foreach($fields as $field_value){
                            if($field_value=='id' || $field_value=='updatetime'){
                                continue;
                            }
                            $sqls.=",".$field_value.'= CASE id ';
                            foreach($data[$table] as $values){
                                $sqls.=" WHEN ".$values['id']." THEN '".$values[$field_value]."'";
                            }
                            $sqls.=" END";
                        }
                        if($sqls){
                            $ids = array_column($data[$table], 'id');
                            $sql.=substr($sqls,1)." WHERE id IN (".implode(',',$ids).")";
                            $Model = new \Think\Model();
                            $Model->execute($sql);
                        }
                    }
                    if($tableData['add']){
                        //数据添加
                        $data[$table]=$tableData['add'];
                        array_walk($data[$table],array($this,'buchongFields'),$nodeEditFields);
                        $this->addModelFieldTable($model_id,$data[$table],$data['id']);
                    }

                }
            }
        }
    }

    /**
     * 修改数据对数据进行自动填充规则
     * @param  $data
     * @return mixed
     */
    public function _befor_update($data, $is_edit=0)
    {
        //节点信息
        $nodeInfo = Task::nodeInfoByTask($data['id'], $this->process_id);
        if ($nodeInfo['type']=='start') {
            //首节点可编辑 即为驳回编辑 审核状态重置
            $data['audit'] = 1;
        }
        
        if ($nodeInfo['type']!='start' && $is_edit==1 && empty(input('post.node_id'))) {
            //提交/编辑信息，下个节点还未审核时，再次编辑操作  不包含列表派单等情况
            $data['audit'] = 1;
            //取当前流程首节点
            $data['edit_node_id'] = Task::returnNodeId(0, $this->process_id);          
        }

        return $data;
    }

    /**
     * 编辑操作
     */
    public function edit()
    {
        $model = Db::name($this->dbName);
        
        if (input('post.')) {
            //主MODEL 执行function
            if (method_exists($this, 'model_func')) {
                $this->model_func();
            }
            //编辑操作
            $is_edit = 1;
            $this->audit_edit_post($model, $is_edit);
            mtReturn(200, "操作成功", $this->redirectUrl);
        }

        $id = input('param.'.$model->getPk(), 0);
        if (method_exists($this, '_befor_edit')) {
            //模型字段的相关数据
            $datas = $this->_befor_edit($id);
        }

        //编辑时当前节点可修改字段
        $this->nodeEditFields($id);

        //redis取数据
        $vo = getDataById($this->dbName, $id);
        $vo = array_merge($vo, $datas);
        
        $taskData = getDataById('task_'.$this->process_id, $id, "work_id");
        $this->checkAuth($taskData);

        // 模版渲染
        return view('edit', [
            'smallTitle'  => '编辑数据',
            'data'        => $vo,
            'id'          => $id
        ]);
    }

    /**
     * 审核操作时，只需验证该节点需要填写字段是否为空
     * @param  $data
     * @param  $fields
     * @return mixed
     */
    protected function handleAuditPost($data, $fields)
    {
        $fields = array_keys($fields);
        foreach ($data as $field=>&$value) {
            if (!in_array($field, $fields) && empty($value)) {
                $value = null;
            }
        }
        return $data;
    }

    /**
     * 审核/编辑提交数据处理
     * @param  $model
     * @param  $is_edit
     */
    public function audit_edit_post($model, $is_edit=0)
    {
        //审核操作时，只需验证该节点需要填写字段是否为空
        $data = input('post.');
        $editFields = $this->nodeEditFields($data['id'], true);
        $data = $is_edit ? input('post.') : $this->handleAuditPost($data, $editFields);
        
        if (method_exists($this, '_befor_update')) {
            $data = $this->_befor_update($data, $is_edit);
        }

        //备注时审核状态为空，不保存审核状态
        if (empty($data['audit'])) {
            unset($data['audit']);
        }
        
        //开启事务
        Db::startTrans();
        
        //验证完成器
        $data = validateAuto($data, 'edit');
        
        if ($model->strict(false)->where('id', $data['id'])->update($data)) {
            $id = $data['id'];
            
            //保存Redis
            $basic_data = getRedis($this->dbName, $id);
            
            //审核驳回发送微信消息
            if (isset($data['audit']) && $data['audit']==-1) {
                $lc_orders_id = $data['lc_orders_id'] ?? $data['id'];
                //sendWxMessage('o586vwU0k2eUuXBGHsbGgbu9kp4s', 1, array('first'=>"流程单号：".$lc_orders_id, 'keyword1'=>$this->pageTitle, 'keyword2'=>"驳回", 'remark'=>input('post.auditvalue',' ')));
            }

            $datas = input('post.');
            //audit:-1 驳回时 不保存信息
            $datas['audit'] = $datas['audit'] ?? 0;
            if (method_exists($this, '_after_edit') && $datas['audit']!=-1) {
                $datas['id'] = $id;
                $this->_after_edit($datas);
            }
            if ($basic_data) {
                $redis_data = array_merge($basic_data, $data);
            }
            setRedis($this->dbName."_".$id, $redis_data);
            
            //值为空时表示备注 流程不流转
            $isremark = (!empty($datas['audit']) || !empty($data['audit'])) ? 0 : 1;
            
            //更新任务
            $data['edit_node_id'] = $data['edit_node_id'] ?? 0;
            $node_id = $datas['node_id'] ?? $data['edit_node_id'];
            $this->updateTask($id, $node_id, $isremark);
            
            Db::commit();

            addAudit($data, $this->process_id, input('post.auditvalue'));
        } elseif ($data['auditvalue']) {
            //在审核中备注
            addAudit($data, $this->process_id, input('post.auditvalue'));
        }
    }
    
    /**
     * 列表驳回操作，任何节点都可以驳回
     */
    public function reject()
    {
        $model = Db::name($this->dbName);
        
        if (input('post.')) {
            $data['audit'] = -1; //驳回    
            $data['id']    = input('post.id');
            
            //验证完成器
            $data = validateAuto($data, 'reject');
            
            if ($model->save($data)) {
                $id = $data['id'];

                $basic_data = getRedis($this->dbName, $id);
                
                //审核驳回发送微信消息
                //sendWxMessage('o586vwU0k2eUuXBGHsbGgbu9kp4s', 1, array('first'=>"流程单号：".$id, 'keyword1'=>$this->pageTitle, 'keyword2'=>"驳回", 'remark'=>input('post.auditvalue',' ')));
                
                //更新Redis
                updateRedis($this->dbName, $id, $data);

                //更新任务
                $this->updateTask($id, 0);
                
                //审核信息保存
                addAudit($data, $this->process_id, input('post.auditvalue'));
            }
            mtReturn(200, "驳回成功", $this->redirectUrl);
        }
        
        $id = input('param.'.$model->getPk(), 0);
        
        // 模版渲染
        return view('remark', [
            'id'     => $id,
            'ptitle' => "驳回",
            'action' => strtolower(app('request')->action())
        ]);
    }

    /**
     * 列表备注功能额外处理信息
     * @param $data
     */
    public function _after_remarks($data)
    {
        switch ($this->process_id) {
            case 1:
                $value = getDataById($this->dbName, $data['id']);
                $now   = date('Y-m-d H:i',time());
                $info  = session('uid')."于".$now."编写了备注信息";
                //..推送消息
                break;
            default:
                break;
        }
    }

    /**
     * 列表备注操作，任何节点都可以备注 不影响流程流转
     */
    public function remark()
    {
        if (input('post.')) {
            $data['id'] = input('post.id');
            
            //备注信息保存
            addAudit($data, $this->process_id, input('post.auditvalue'));
            
            if (method_exists($this, '_after_remarks')) {
                $this->_after_remarks($data);
            }
            
            mtReturn(200, "备注成功", $this->redirectUrl);
        }
        
        $model = Db::name($this->dbName);
        $id    = input('param.'.$model->getPk(), 0);
        
        // 模版渲染
        return view('remark', [
            'id'     => $id,
            'ptitle' => "流程备注",
            'action' => strtolower(app('request')->action())
        ]);
    }

    /**
     * 用户拥有ACTION权限 限制用户针对某一条记录当前是否有权限操作
     * @param $data
     */
    public function checkAuth($data)
    {
        $name = $this->controller.'/'.strtolower(app('request')->action());
        if (!display($name, $data)) {
            mtReturn(300, session('uid').'很抱歉,此项操作您没有权限！', $this->redirectUrl);
        }
    }

    /**
     * 流程实例对应节点可编辑的字段
     * @param $id
     */
    protected function nodeEditFields($id, $is_return=false)
    {
        //节点信息
        $nodeInfo = Task::nodeInfoByTask($id, $this->process_id);
        //返回节点可编辑字段
        $writablefields = $nodeInfo['writablefields'] ? parse_field_attr($nodeInfo['writablefields']) : array();
        //编辑时所有字段都可编辑
        if (strtolower(app('request')->action())=="edit") {  
            $writablefields = array();
        }
        View::assign([
            'writablefields' => $writablefields
        ]);
        if ($is_return) {
            return $writablefields;
        }
    }

    /**
     * 编辑时取字段为model时相关数据
     * @param  $id
     * @return array
     */
    public function _befor_edit($id)
    {
        $datas = array();
        foreach ($this->fields as $key=>$field) {
            if ($field['type']=='model') {
                $table    = $field['extra']['table'];
                $model_id = $field['extra']['model_id'];
                $models   = logic($model_id);
                if (in_array($this->dbName."_id", $models->getDbFields())) {
                    $map[$this->dbName."_id"] = $id;
                    $map['status'] = 1;
                    $datas[$table] = $models->where($map)->select()->toArray();
                }
            }
        }
        return $datas;
    }

    /**
     * 流程节点审核
     * @param int $audit
     */
    public function audit($audit=-1)
    {
        if (input('post.ids')) {
            //批量审核操作
            $this->audithandle();
        }

        $model = Db::name($this->dbName);
        
        if (input('post.')) {
            $id = ('post.id');
            
            //提交操作判断具体每条记录的操作权限
            $taskData = getDataById('task_'.$this->process_id, $id, "work_id");
            
            $this->checkAuth($taskData);
            
            $this->audit_edit_post($model);
            
            mtReturn(200, "操作成功", $this->redirectUrl);
        }

        $id = input('param.'.$model->getPk(), 0);
        if (method_exists($this, '_befor_edit')) {
            //模型字段的相关数据
            $datas=$this->_befor_edit($id);
        }
        
        //编辑时当前节点可修改字段
        $this->nodeEditFields($id);

        //redis取数据
        $vo = getRedis($this->dbName, $id);
        if (!$vo) {
            $vo = $model->getById($id);
        }
        $vo = array_merge($vo, $datas);
        
        // 模版渲染
        return view('audit', [
            'smallTitle' => '审核操作',
            'data'       => $vo,
            'audit'      => $audit,
            'id'         => $id
        ]);
    }

    /**
     * 批量审核操作
     * 批量审核audit，且可编辑属性节点编辑属性有默认值则操作
     */
    public function audithandle()
    {
        //批量审核操作
        if (input('post.ids')) {
            
            $id = input('get.id') ?? input('post.ids');
            
            //批量审核当前登录用户可审核记录
            $map[] = array('work_id', 'in', $id);
            $map[] = array('action_uids', 'like', '%,'.session("uid").',%');
            $ids = $this->model->alias('a')->join('processnode b', 'b.id = a.node_id')->where($map)->column('writablefields','work_id');

            $sqls        = "";
            $dataList    = array();
            $fields      = array();
            $audit_ids   = array();
            $count       = count($ids) ?? 0;
            $break_count = 0; //审核时有必填字段记录数  
            
            if ($ids) {
                $sql = "UPDATE ".Config::get('database.connections.mysql.prefix').$this->dbName." SET audit=CASE id ";
                foreach ($ids as $work_id=>$writablefields) {
                    $audits  = parse_field_attr($writablefields);
                    $isbreak = false;
                    //有可编辑属性且没有默认值，则不审核
                    foreach ($audits as $value) {
                        if ($value==null || $value=="") {
                            $break_count+=1;
                            $isbreak = true;
                            break;
                        }
                    }
                    if ($isbreak) {
                        continue;
                    }
                    if (isset($audits['audit'])) {
                        $sqls .= " WHEN ".$work_id." THEN ".$audits['audit'];
                        //审核信息保存
                        $dataList[]  = array('work_id'=>$work_id, 'audit'=>$audits['audit']);
                        $audit_ids[] = $work_id;
                    }
                    foreach ($audits as $field=>$value) {
                        if ($field=="audit") {
                            continue;
                        }
                        $fields[] = $field;
                        $$field   = '';
                        $$field  .= " WHEN ".$work_id." THEN '".$value."'"; 
                    }
                }

                if ($dataList) {
                    //审核信息保存
                    muliAddAudit($dataList, $this->process_id);
                }
                if ($sqls) {
                    $sql .= $sqls." END ";
                    foreach ($fields as $field) {
                        $sql .= ','.$field." = CASE id ".$$field." END "; 
                    }
                    $sql .= " WHERE id IN (".arr2str($audit_ids,',').")";
                    Db::query($sql);
                    //流程任务
                    foreach ($audit_ids as $work_id) {
                        delRedis($this->dbName."_".$work_id);
                        $this->updateTask($work_id);
                    }
                }
            }
            
            $audit_num = $count-$break_count;
            $message   = "共审核成功 <b>".$audit_num."</b> 条数据。";
            $message  .= $break_count ? "有".$break_count."条记录审核有必填字段不能批量审核" : "";
            $re        = array("message"=>$message, "type"=>'success');

            mtReturn(200, $message, $this->redirectUrl);
        }
    }

    /**
     * 任务修改
     * @param $work_id
     * @param int $node_id
     * @param int $isremark
     */
    protected function updateTask($work_id, $node_id=0, $isremark=0)
    {
        $vo      = getRedis($this->dbName, $work_id);
        $task    = getDataById('task_'.$this->process_id, $work_id, "work_id");
        $vo      = array_merge($task, $vo);
        $node_id = $node_id>0 ? $node_id : $task['node_id'];
        
        Task::addTask($vo, $this->process_id, $node_id, $isremark);
    }

    /**
     * 查看详情
     */
    public function view()
    {
        $model = Db::name($this->dbName);
        
        $id = input('param.'.$model->getPk(), 0);
        if (method_exists($this, '_befor_edit')) {
            //模型字段的相关数据
            $datas = $this->_befor_edit($id);
        }
        
        //redis取数据
        $vo = getRedis($this->dbName, $id);
        if (!$vo) {
            $vo = $model->getById($id);
        }
        $vo = array_merge($vo, $datas);
        
        //编辑时当前节点可修改字段
        $this->nodeEditFields($id);

        //操作记录
        $auditlist = Db::name('audit_'.$this->process_id)->where('work_id', $id)->order('id desc')->select()->toArray();
        $audit_xuzhong = array();
        foreach ($auditlist as $alist) {
            $audit_xuzhong[] = $alist;
            //重置流程详情页流程流转则不显示之前流程走向
            if (isset($alist['auditvalue']) && $alist['auditvalue']=='流程重置') {
                break;
            }
        }
        $auditLans = parse_field_attr($this->fields['audit']['extra']);
        
        //详情只显示大于-1的值
        foreach ($auditLans as $l_key=>$l_value) {
            if ($l_key>=-1) {
                $auditLan[$l_key] = $l_value;
            }
        }
        
        // 模版渲染
        return view('view', [
            'audit_xuzhong' => $audit_xuzhong, 
            'auditlist'     => $auditlist,
            'auditLans'     => $auditLans,
            'auditLan'      => $auditLan,
            'data'          => $vo,
            'id'            => $id
        ]);
    }

    /**
     * task记录标记删除，执行delexecute
     * @param $id
     */
    public function _befor_delete($id)
    {
        $data['status']     = 0;
        $data['updatetime'] = time();
        $this->model->where('work_id', 'in', $id)->update($data);
        
        //作废时执行
        if ($this->delexecute) {
            $delexecute = str_replace('{$work_id}', $id, $this->delexecute);
            $rules      = handleRule($delexecute);
            foreach ($rules as $key=>&$arr) {
                execute($arr['name'], $arr['vars']);
            }
        }
    }
}
