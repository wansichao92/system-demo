<?php
namespace app\model;

use think\Model;
use think\facade\Db;
use app\model\AuthGroup;

class Task extends Model
{
    /**
     * 由task相关数据取出当前任务节点信息
     * @param  $work_id
     * @param  $process_id
     * @return mixed|string
     */
    static public function nodeInfoByTask($work_id, $process_id)
    {
        //当前数据对应任务记录 取出节点ID
        $task = getDataById('Task_'.$process_id, $work_id, "work_id");
        //节点信息
        $nodeInfo = getDataById('processnode', $task['node_id']);
        
        return $nodeInfo;
    }

    /**
     * @param  array $data
     * @param  int   $process_id
     * @param  int   $node_id
     * @param  int   $isbeizhu   1:备注信息 流程不流转 0：非备注需要流程流转
     * @return bool
     */
    static function addTask($data, $process_id, $node_id=0, $isbeizhu=0, $is_start_node=true, $sessionuid=0)
    {
        $work_id = $data['work_id'] ?? $data['id'];
        $model   = Db::name('task_'.$process_id);
        
        //流程重置时不为起始节点
        if ($is_start_node===true) {
            //判断是否为首节点
            $is_start_node = $node_id ? false : true;
        }
        
        //取NODE_ID    
        $node_id = self::returnNodeId($node_id, $process_id); 
        
        //当前节点需要处理事务
        self::handleCurrentNode($node_id, $work_id, $data);
        
        //execute执行后data信息可能发生改变，取最新data数据
        $processInfo   = getProcessById($process_id);
        $process_table = $processInfo['relatedtable'];
        $p_data        = getRedis($process_table, $work_id);
        $task_data     = getDataById('task_'.$process_id, $work_id, "work_id");
        $data          = $task_data ? array_merge($task_data, $p_data) : $p_data;
        
        //备注 流程不流转
        if ($isbeizhu==1) {
            return true;
        }
        
        $nextNodeInfo = self::returnNextNode($process_id, $work_id, $node_id, $data['audit']);
        //流程流转前一节点ID
        $datas['pre_node_id'] = $node_id;             
        $datas['audit']       = $data['audit'];
        
        //当前节点流转的流程信息 取当前的节点信息
        $process = self::returnProcess($node_id, $work_id, $process_id);
        if ($process) {
            $datas['process'] = $process;
        }
        if (!$nextNodeInfo) {
            //最后一个节点操作
            $datas['state']       = 'Completed';
            $datas['action_uids'] = '';
            $datas['action_role'] = '';
            $datas['uuid']        = session('uid'); //当前审核人
            $datas['alarm']       = 0; //流程结束则不提示VIP
            
            $map['work_id'] = $work_id;
            $model->where($map)->update($datas);
            $datas = array_merge($data, $datas);
        } else {
            $action_uids = "";
            $datas['action_role'] = 0; //默认为空
            if ($nextNodeInfo['role']) {
                //角色控制节点
                $path = '';
                if ($nextNodeInfo['role1']=="PromoterLeader") {
                    $pro_uid = session('uid');
                    $path    = session('userInfo.path');
                    if ($nextNodeInfo['leaderpro']) {
                        //此处操作为“leaderpro”属性指定表单某属性的领导 如：业务员属性值的领导操作
                        $pro_uid = $data[$nextNodeInfo['leaderpro']];
                        $path    = returnPathByUid($pro_uid);
                    }
                }else{
                    //不是取领导为执行人时 保存角色
                    $datas['action_role'] = $nextNodeInfo['role']; //角色控制
                }
                $pro_uid     = $nextNodeInfo['isdirectLeader'] ? $pro_uid : 0; //只取顶头上司一人为审核人
                $action_uids = AuthGroup::memberInGroup($nextNodeInfo['role'], $path, $process_id, $pro_uid);
                $action_uids = is_array($action_uids) ? implode(',',$action_uids) : $action_uids;
            }
            if ($nextNodeInfo['role1']) {
                switch ($nextNodeInfo['role1']) {
                    case 'Promoter':
                        //任务发起人
                        $uids = $data['uid'];
                        break;
                    case 'PromoterLeader':
                        //工作流启动者领导
                        $uids = 0;
                }
                $action_uids = $action_uids ? $action_uids.",".$uids : $uids;
            }
            if ($nextNodeInfo['role2']) {
                //指定具体用户工号
                $uids = str_replace('&',',',$nextNodeInfo['role2']);
                $action_uids = $action_uids ? $action_uids.",".$uids : $uids;
            }
            if ($nextNodeInfo['role3']) {
                //动态属性值人员控制节点
                $uids = self::returnAttributeData($nextNodeInfo['role3'], $data);
                $action_uids = $action_uids ? $action_uids.",".$uids : $uids;
            }
            $datas['action_uids'] = $action_uids ? (is_array($action_uids) ? ','.implode(',',$action_uids).',' : ','.$action_uids.',') : '';
            
            //节点报警时间计算 当前时间+节点允许操作时间
            $datas['expire']  = $nextNodeInfo['expire'] ? time()+$nextNodeInfo['expire']*60 : 0;
            $datas['node_id'] = $nextNodeInfo['id'];
            if ($nextNodeInfo['refer_rule']) {
                $datas['refer']     = ",".self::returnAttributeData($nextNodeInfo['refer_rule'], $data).",";
                $datas['refer_dep'] = getDepOfUids($datas['refer']);
            }
            if ($is_start_node) {
                //首节点任务补加
                $datas['uid']          = $data['uid'] ?? getuserid();
                $datas['dep']          = getDepOfUids($datas['uid']); //用户所属部门
                $datas['work_id']      = $work_id;
                $datas['related_uids'] = $datas['action_uids'];
                $datas['related_dep']  = getDepOfUids($datas['related_uids']);
                $datas['status']       = 1;
                $datas['dateline']     = time();
                
                $model->strict(false)->insert($datas);
            } else {
                //非首节点
                $datas['uuid']         = $sessionuid ?? session('uid'); //当前审核人
                $datas['related_uids'] = $datas['action_uids'] ? ",".$datas['uuid'].$datas['action_uids'].substr($data['related_uids'],1) : $data['related_uids'];
                $datas['related_dep']  = getDepOfUids($datas['related_uids']);
                $datas['updatetime']   = time();
                
                $map['work_id'] = $work_id;
                $model->strict(false)->where($map)->update($datas);
                $datas = array_merge($data, $datas);
            }
        }
        
        //更新redis
        updateRedis('task_'.$process_id, $work_id, $datas);
        
        //当前节点需要处理事务 Task表处理之后执行
        self::handleCurrentNode($node_id, $work_id, $data, "nextTaskExecute");
        
        //由前一流程流转 处理前一流程相关信息
        self::preprocessTask($process_id);

        return true;
    }

    /**
     * 返回NODE_ID | 流程在开始节点时，返回第一个节点ID
     * @param  int $node_id
     * @param  int $process_id
     * @return int
     */
    static public function returnNodeId($node_id=0, $process_id=0)
    {
        if (!$node_id) {
            //首节点
            $map['process_id'] = $process_id;
            $map['type']       = "start";
            //查询节点信息
            $info = Db::name('Processnode')->where($map)->find();
            $node_id = $info['id'];
        }
        
        return $node_id;
    }

    /**
     * 当前节点任务执行完后需要处理事务
     * @param $node_id
     * @param $work_id
     */
    static function handleCurrentNode($node_id, $work_id, $data, $execute="execute")
    {
        if ($node_id) {
            $nodeInfo = self::nodeInfo($node_id);
            if ($nodeInfo[$execute]) {
                //当前节点需执行方法
                $nodeInfo[$execute] = str_replace('{$work_id}', $work_id, $nodeInfo[$execute]);
                $nodeInfo[$execute] = handleAttributeValue($nodeInfo[$execute], $data);
                $rules = handleRule($nodeInfo[$execute]);
                foreach ($rules as $key=>&$arr) {
                    execute($arr['name'], $arr['vars']);
                }
            }
        }
    }

    /**
     * 查询节点信息
     * @param  int $node_id
     * @param  int $process_id
     * @return mixed
     */
    static public function nodeInfo($node_id=0)
    {
        $info = getDataById("Processnode", $node_id);
        return $info;
    }

    /**
     * 流程流转 返回下一节点信息
     * @param  $process_id
     * @param  $work_id
     * @param  $node_id
     * @param  int $audit    当前流程审核状态
     * @return mixed
     */
    static public function returnNextNode($process_id, $work_id, $node_id, $audit=0)
    {
        //流程信息
        $process = getProcess();
        $process_table = $process[$process_id]['relatedtable'];
        
        //查询节点信息
        $info = self::nodeInfo($node_id);
        $expression = isset($info['expression']) ? str2arr($info['expression'],'&') : "";
        
        $vo = getDataById('task_'.$process_id, $work_id, "work_id");
        
        if ($vo['audit']==-1 && is_array($expression) && $audit!=-1) {
            if (in_array('LastReturnFrom', $expression)) {
                //驳回 且 该节点表达式字段设置LastReturnFrom变量，则驳回后操作跳过之前审核通过的节点 并且当前审核状态不是驳回
                $nextnode = $vo['pre_node_id'];
            }
        } else {
            //流程正常流转
            //解析规则:table:$table|field:$field|condition:$condition|rule:$rule[|cycle:$cycle|max:$max][;......]
            $rules  = $info['noderule'];
            $rules  = str_replace('{$work_id}', $work_id, $rules);
            $ruless = self::analyzeRule($rules);
            
            $nextnode = false;
            foreach ($ruless as $rule) {
                //查询下一节点
                $Model = self::ruleModel($rule);
                $rule['condition'] = str_replace('{$work_id}', $work_id, $rule['condition']);
                $where = $process_table==$rule['table'] ? $rule['condition']." AND a.id=".$work_id : $rule['condition'];
                $field = $rule['field'] ?? "id";
                $res = $Model->where($where)->column($field);
                
                if ($res && isset($rule['yesnode'])) {
                    $nextnode = $rule['yesnode'];
                    break;
                } elseif (!$res && isset($rule['nonode'])) {
                    $nextnode = $rule['nonode'];
                    break;
                }
            }
        }
        
        $nextnodeInfo = self::nodeInfo($nextnode);

        return $nextnodeInfo;
    }

    /**
     * 解析规则
     * @param $rules
     * @return array
     */
    static public function analyzeRule($rules)
    {
        $rules  = handleAttributeValue($rules);
        $rules  = explode(';', $rules);
        $ruless = array();
        foreach ($rules as $key=>&$rule) {
            $rule = explode('|', $rule);
            foreach ($rule as $k=>$fields) {
                $field = empty($fields) ? array() : explode(':', $fields);
                if (!empty($field)) {
                    $ruless[$key][$field[0]] = $field[1];
                }
            }
        }
        return $ruless;
    }

    static public function ruleModel($rule)
    {
        $tables = str2arr($rule['table']);
        $Model  = Db::name(ucfirst($tables[0]))->alias('a');
        if (isset($rule['on']) && $tables[1]) {
            $Model->join($tables[1].' b',$rule['on']);
        }

        return $Model;
    }

    /**
     * 流程与流程流转，处理前一流程相关状态
     */
    static function preprocessTask($process_id)
    {
        $pre_process_id = input('post.pre_process_id');
        $work_id        = input('post.work_id');
        $p_processextra = input('post.processextra');
        
        if ($pre_process_id && $work_id) {
            
            //由前流程流转 处理前流程相关状态
            $taskTable    = 'task_'.$pre_process_id;
            $task         = getDataById($taskTable, $work_id, "work_id");
            $string       = $task['process'];
            $process_data = str2arr($string,'</a>');
            
            if ($process_data) {
                $val = array();
                foreach ($process_data as $key=>$url) {
                    if (!$url) {
                        continue;
                    }

                    //截取title的正则
                    $pattern2 = '/\/process_id\/(.*).html\"/U';                 
                    preg_match_all($pattern2, $url, $process);
                    //截取processextra额外参数的正则
                    $pattern2 = '/\/processextra\/(.*)\/process_id\/'.$process[1][0].'.html\"/U';                 
                    preg_match_all($pattern2, $url, $processextra);
                    
                    if ($process[1][0]==$process_id && $p_processextra==$processextra[1][0]) {
                        //截取title的正则
                        $pattern2 = '/\">(.*)\<i class=\"fa fa-\w+\"><\/i>/U';                 
                        preg_match_all($pattern2, $url, $titles);
                        //截取title的正则
                        $pattern2 = '/href\=\"(.*)\"/U';                 
                        preg_match_all($pattern2, $url, $hrefs);
                        //截取title的正则
                        $pattern2 = '/class\=\"btn btn-sm(.*)\"/U';                 
                        preg_match_all($pattern2, $url, $class);
                        $href = str_replace("add","index",$hrefs[1][0]);
                        $val[] = '<a data-process="'.$process_id.'" data-action="index" class="btn btn-sm'.$class[1][0].'" data-url="'.$href.' #index-body" data-id="dialog-mask" data-width="1000" data-toggle="partmodal">'.$titles[1][0].'<i class="fa fa-eye"></i></a>';
                    } else {
                        $val[] = $process_data[$key]."</a>";
                    }
                }
                
                $model = Db::name($taskTable);
                $data  = validateAuto($data, 'edit');
                $data['process'] = arr2str($val, '');
                $model->where("work_id", $work_id)->update($data);
                
                //更新REDIS表信息
                updateRedis($taskTable, $work_id, $data);                     
            }
        }
    }

    /**
     * 动态取属性值
     * @param $string
     * @param $data
     * @return string
     */
    static function returnAttributeData($string, $data){
        $refers=str2arr($string,'&');
        $refer="";
        foreach($refers as $value){
            if($data[$value])
                $refer.=",".$data[$value];
        }
        return substr($refer,1);
    }

    /**
     * 跳转至需要流转的流程
     * @param  $node_id
     * @param  $work_id
     * @param  $process_id
     * @return string
     */
    static public function returnProcess($node_id, $work_id, $process_id)
    {
        $nodeInfo = getDataById("processnode", $node_id);
        $value    = "";
        if ($nodeInfo['actionrule']) {
            //流程任务表 取出相应process值 相匹配
            $task_data              = getDataById('task_'.$process_id, $work_id, "work_id");
            $task_process           = $task_data['process'];
            $nodeInfo['actionrule'] = str_replace('{$work_id}', $work_id, $nodeInfo['actionrule']);
            $ruless                 = self::analyzeRule($nodeInfo['actionrule']);
            
            foreach ($ruless as $rule) {
                //查询下一节点
                $Model = self::ruleModel($rule);
                $where = str_replace('{$work_id}', $work_id, $rule['condition']);
                $res   = $Model->where($where)->find();
                if ($res) {
                    if (strpos($task_process, "process_id/".$rule['process_id'])!==false) {
                        //已记录此子流程信息
                        continue;
                    }
                    $show         = $rule['name'];
                    $class        = $rule['class'];
                    $tips         = $rule['tips'];
                    $processextra = $rule['processextra']; //链接额外参数
                    $href1        = "add?pre_process_id=".$process_id."&work_id=".$work_id;
                    $href1        = $processextra ? $href1."&processextra=".$processextra : $href1;
                    $href         = $href1."&process_id=".$rule['process_id'];
                    $val[]        = '<a data-process="'.$rule['process_id'].'" data-action="add" data-container="body" data-original-title="'.$tips.'" class="btn btn-sm '.$class.' tooltips" href="'.U($href).'">'.$show.'<i class="fa fa-edit"></i></a>';
                } elseif (!$res && strpos($task_process, "process_id/".$rule['process_id'])!==false) {
                    //取消已存在的子流程跳转信息
                    $pattern2 = '/<a .* href\=\"(.*)process_id\/'.$rule['process_id'].'.*\".*<\/a>/U';
                    preg_match_all($pattern2, $task_process, $exist_process);
                    $task_process = str_replace($exist_process[0][0], "", $task_process);
                }
            }
            $value = $task_process.arr2str($val,'');
        }
        return $value;
    }

    static public function alarmTask($process_id)
    {
        $taskTable = 'task_'.$process_id;
        $model=logics($taskTable);
        $model->alarm($process_id);
    }
}