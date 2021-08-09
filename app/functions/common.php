<?php
// 应用公共文件

use think\facade\Db;
use think\facade\Config;
use app\model\AuthGroup;
use think\wenhainan\Auth;
use app\controller\Execute;
use app\validate\validateAuto;
use think\exception\ValidateException;

/**
 * 系统加密方法
 * @param string    $data    要加密的字符串
 * @param string    $key     加密密钥
 * @param int       $expire  过期时间 单位 秒
 * @return string
 */
function think_encrypt($data, $key='', $expire=0){
    $key  = md5(empty($key) ? NULL : $key); //C('DATA_AUTH_KEY')找不到，用null代替
    $data = base64_encode($data);
    $x    = 0;
    $len  = strlen($data);
    $l    = strlen($key);
    $char = '';
    for ($i=0; $i<$len; $i++) {
        if ($x == $l) $x = 0;
        $char .= substr($key, $x, 1);
        $x++;
    }
    $str = sprintf('%010d', $expire ? $expire + time():0);
    for ($i=0; $i<$len; $i++) {
        $str .= chr(ord(substr($data, $i, 1)) + (ord(substr($char, $i, 1)))%256);
    }
    return str_replace(array('+','/','='),array('-','_',''),base64_encode($str));
}

/**
 * 系统解密方法
 * @param  string    $data    要解密的字符串 （必须是think_encrypt方法加密的字符串）
 * @param  string    $key     加密密钥
 * @return string
 */
function think_decrypt($data, $key=''){
    $key  = md5(empty($key) ? NULL : $key); //C('DATA_AUTH_KEY')找不到，用null代替
    $data = str_replace(array('-','_'),array('+','/'),$data);
    $mod4 = strlen($data) % 4;
    if ($mod4) {
        $data .= substr('====', $mod4);
    }
    $data   = base64_decode($data);
    $expire = substr($data,0,10);
    $data   = substr($data,10);
    if($expire > 0 && $expire < time()) {
        return '';
    }
    $x    = 0;
    $len  = strlen($data);
    $l    = strlen($key);
    $char = $str = '';
    for ($i=0; $i<$len; $i++) {
        if ($x==$l) $x = 0;
        $char .= substr($key, $x, 1);
        $x++;
    }
    for ($i=0; $i<$len; $i++) {
        if (ord(substr($data, $i, 1))<ord(substr($char, $i, 1))) {
            $str .= chr((ord(substr($data, $i, 1)) + 256) - ord(substr($char, $i, 1)));
        }else{
            $str .= chr(ord(substr($data, $i, 1)) - ord(substr($char, $i, 1)));
        }
    }
    return base64_decode($str);
}

function parse_field_condition_attr($string, $par=":"){
    $rule  = explode('|', $string);
    $value = array();
    foreach ($rule as $fields) {
        $field = empty($fields) ? array() : explode($par, $fields);
        if (!empty($field)) {
            $value[$field[0]] = $field[1];
        }
    }
    return $value;
}

/**
 * 获取客户端ip
 */
function get_client_ip(){
    if (getenv("HTTP_CLIENT_IP") && strcasecmp(getenv("HTTP_CLIENT_IP"), "unknown")) {
        $ip = getenv("HTTP_CLIENT_IP");
    } else if (getenv("HTTP_X_FORWARDED_FOR") && strcasecmp(getenv("HTTP_X_FORWARDED_FOR"), "unknown")) {
        $ip = getenv("HTTP_X_FORWARDED_FOR");
    } else if (getenv("REMOTE_ADDR") && strcasecmp(getenv("REMOTE_ADDR"), "unknown")) {
        $ip = getenv("REMOTE_ADDR");
    } else if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], "unknown")) {
        $ip = $_SERVER['REMOTE_ADDR'];
    } else {
        $ip = "unknown";
    }
    return $ip;
}

/**
 * 列表搜索信息存储至COOKIE 以便页面读取
 */
function setSearchCookie($re=0, $process_id=0){
    $searchs  = input('param.');
    $unsetKey = ['draw','columns','order','search','start','length'];
    foreach ($unsetKey as $key) {
        unset($searchs[$key]);
    }
    $searchs['folder'] = input('param.folder') ?? "";
    if ($re==1) return $searchs;
    if ($searchs) {
        $cookie_name = $process_id ? "searchData_".$process_id : "searchData";
        cookie($cookie_name, serialize($searchs), 3600*8);
    }
}

/**
 * 根据模型ID获取对应的字段
 * @param    int           $model_id
 * @param    bool          $isAuto
 * @return   array|mixed
 */
function getAttribute($model_id=0, $isAuto=false){
    $attributes = getRedis('ATTRIBUTE_DATA_'.$model_id.'_'.$isAuto);
    if (!$attributes) {
        $map['status'] = 1;
        if ($model_id) {
            $map['model_id'] = $model_id;
        }
        // 模型自动完成时按函数名排序
        $sort = $isAuto ? 'auto_rule' : 'sort desc,id ASC';
        $attribute = Db::name('attribute')->where($map)->order($sort)->select()->toArray();
        $attributes = array();
        foreach ($attribute as $value) {
            if (in_array($value['type'], Config::get('app.model_array'))) {
                //关联模型字段
                $extra = parse_field_attr($value['extra']);
                $fields = getAttribute($extra['model_id']);
                $value['extra'] = $extra;
                $value['fields'] = $fields;
            }
            if ($value['linkage']) {
                //多对多联动字段 多个值联动字段
                $linkage = str2arr($value['linkage'], '|');
                $keys = $values = array();
                foreach ($linkage as $v) {
                    $linkages = str2arr($v, ':');
                    $keys[]   = $linkages[0];
                    $values[] = $linkages[1];
                }
                $value['linkage'] = array(arr2str($keys, '|'), arr2str($values, '|'));
            }
            if ($value['linkview']) {
                $value['linkview'] = parse_field_attr($value['linkview']);
            }
            $attributes[$value['name']] = $value;
        }
        setRedis('ATTRIBUTE_DATA_'.$model_id.'_'.$isAuto, $attributes);
    }
    return $attributes;
}

/**
 * 分析枚举类型字段值 格式 a:名称1,b:名称2
 * 暂时和 parse_config_attr 功能相同
 * 但请不要互相使用，后期会调整
 */
function parse_field_attr($string){
    if (0===strpos($string, ':')) {
        // 采用函数定义
        return eval('return '.substr($string,1).';');
    } elseif (0===strpos($string,'[')) {
        // 支持读取配置参数（必须是数组类型）
        return C(substr($string,1,-1));
    }
    $array = preg_split('/[,;|\r\n]+/', trim($string, ",;\r\n"));
    if (strpos($string,':')) {
        $value = array();
        foreach ($array as $val) {
            list($k, $v) = explode(':', $val);
            $value[$k]   = $v;
        }
    } else {
        $value = $array;
    }
    return $value;
}

/**
 * 字符串转换为数组，主要用于把分隔符调整到第二个参数
 * @param  string $str  要分割的字符串
 * @param  string $glue 分割符
 * @return array
 */
function str2arr($str, $glue=','){
    return $str ? explode($glue, $str) : $str;
}

/**
 * 处理文档列表显示
 * @param array   $list     列表数据
 * @param integer $model_id 模型id
 */
function parseDocumentList($list, $attrList, $labelclass=array()){
    // 对列表数据进行显示处理
    if (is_array($list)) {
        $labelclass = $labelclass ? $labelclass : array(-1=>'bg-red-thunderbird','label-warning','label-primary','label-success','label-info','bg-green-meadow');
        foreach ($list as $k=>$data) {
            foreach ($data as $key=>$val) {
                if (isset($attrList[$key])) {
                    $data[$key] = valueByFieldData($val, $attrList[$key], $labelclass, $data);
                    if ($attrList[$key]['type']=="table" && $attrList[$key]['is_list_edit']==1) {
                        $options = parse_table_field($attrList[$key]['extra']);
                        $selects = '<select name="'.$key.'" class="form-control" style="display:none;width:120px;"><option value="0" >请选择</option>';
                        if ($options) {
                            foreach ($options as $o_key=>$o_value) {
                                $selected = $o_key==$val ? "selected" : "";
                                $selects .='<option value="'.$o_key.'" '.$selected.'>'.$o_value.'</option>';
                            }
                        }
                        $selects .= '</select>';
                        $data[$key] = '<div>'.$data[$key].'</div>'.$selects;
                    } elseif ($attrList[$key]['type']=="select" && $attrList[$key]['is_list_edit']==1) {
                        //select类型
                        $options = parse_field_attr($attrList[$key]['extra']);
                        $selects = '<select name="'.$key.'" class="form-control" style="display:none;width:120px;"><option value="0" >请选择</option>';
                        if ($options) {
                            foreach ($options as $o_key=>$o_value) {
                                $selected = $o_key==$val ? "selected" : "";
                                $selects .= '<option value="'.$o_key.'" '.$selected.'>'.$o_value.'</option>';
                            }
                        }
                        $selects .= '</select>';
                        $data[$key] = '<div>'.$data[$key].'</div>'.$selects;
                    } elseif ($attrList[$key]['type']=="linktable" && $attrList[$key]['is_list_edit']==1) {
                        //linktable类型
                        $extra = handleAttributeValue($attrList[$key]['extra'],array());
                        $selects = '<select data-extra="'.$extra.'" name="'.$key.'" class="form-control js-data-example-ajax" style="display:none;width:120px;">';
                        if ($val) {
                            $selects .= '<option value="'.$val.'" selected="selected">'.$data[$key].'</option>';
                        }
                        $selects .= '</select>';
                        $data[$key] = '<div>'.$data[$key].'</div>'.$selects;
                    } elseif ($attrList[$key]['type']=="string" && $attrList[$key]['is_list_edit']==1) {
                        //string类型
                        $selects = '<input type="text" name="'.$key.'" class="form-control" style="display:none;width:120px;" value="'.$val.'">';
                        $data[$key] = '<div>'.$data[$key].'</div>'.$selects;
                    }
                    //展示时需要限制字数
                    if ($attrList[$key]['textlimit']) {
                        $data[$key] = '<span title="'.$data[$key].'" style="width: 200px;display: inline-block;overflow: hidden;white-space: nowrap;text-overflow: ellipsis;">'.$data[$key].'</span>';
                    }
                } elseif ($key=="dateline" || $key=="updatetime") {
                    $data[$key] = $val ? date('Y-m-d H:i', $val) : '';
                }
            }
            $list[$k] = $data;
        }
    }
    return $list;
}

/**
 * 据字段类型 返回数据实际值
 * @param $val
 * @param $field
 * @param $labelclass
 * @return bool|string
 */
function valueByFieldData($val, $field, $labelclass=array(), $data=array()){
    $extra          = $field['extra'];
    $type           = $field['type'];
    $is_label_class = $field['is_label_class'];
    $labelclass     = $field['label_class'] ? parse_field_attr($field['label_class']) : $labelclass;
    $label          = $labelclass[$val] ?? 'label-success';
    $re             = "";
    if ('select'==$type || 'radio'==$type || 'bool'==$type) {
        //枚举/单选/布尔型
        $options = parse_field_attr($extra);
        if ($options && array_key_exists($val,$options)) {
            $re = $is_label_class ? '<span class="label label-sm '.$label.'"> '.$options[$val].' </span>' : $options[$val];
        }
    } elseif ('department'==$type) {
        //部门类型
        $re = returnFieldByDataId('title', 'hr_group', $val);
    } elseif ('checkbox'==$type) {
        //多选
        $options = $field['execute'] ? parse_table_field($field['execute'],$data) : parse_field_attr($extra);
        $val = explode(',',$val);
        $values = "";
        foreach ($val as $keys) {
            $values .= ','.$options[$keys];
        }
        $re = substr($values, 1);
        $re = ($is_label_class && $re) ? '<span class="label label-sm '.$label.'"> '.$re.' </span>' : $re;
    } elseif ('table'==$type || ('linktable'==$type && $field['is_multiple']==0)){
        $re = linktableValue($val, $extra);
    } elseif ('linktable'==$type && $field['is_multiple']==1) {
        $re = "";
        if ($val) {
            $rule = parse_field_condition_attr($extra);
            //执行数据库操作
            if (strpos($rule['field'], 'concat')!==false) {
                preg_match_all("/(?:\()(.*)(?:\))/i", $rule['field'], $fields);
                $fields = str2arr($fields[1][0], ',');
                $rule['field'] = $fields[0];
            }
            $tables = str2arr($rule['table']);
            $table = $tables[0];
            $map[$rule['key']] = array('IN',explode(',',$val));
            $data = Db::name($table)->where($map)->getField($rule['field'],true);
            $re = arr2str($data, ",");
        }

    } elseif ('function'==$type || ('liandong'==$type && $field['execute'])) {
        $re = parse_function_field($field['execute']."&val=".$val);
    } elseif ('date'==$type) { 
        // 日期型
        $re = $val ? date('Y-m-d',$val) : '';
    } elseif ('datem'==$type) { 
        // 日期型月份
        $re = $val ? date('Y-m',$val) : '';
    } elseif ('datetime' == $type || 'daterange'==$type || 'daterangetime'==$type) { 
        // 时间型
        $re = $val ? date('Y-m-d H:i',$val) : '';
    } elseif ('file'==$type) { 
        // 文件
        if ($val) {
            $file = get_table_field($val, 'id', '', 'File');
            //图片预览
            $re = $field['file_preview'] ? '<img src="'.$file['url'].'" style="max-width: 100%;max-height: 400px;">' : '';
            $re .= '<div class="upload-pre-file"><i class="fa fa-paperclip"></i> <a target="_blank" href="'.$file['url'].'">'.$file['name'].'</a><a href="'.U('common/xlsview',array('file'=>urlencode($file['url']))).'" target="_blank" class="btn btn-sm btn-primary"><i class="fa fa-search"></i> 预览</a></div>';
        }
    } elseif ('multifile'==$type) { 
        //多文件
        if ($val) {
            $files = Db::name('file')->where('id', 'in', $data[$field['name']])->select()->toArray();
            if ($files) {
                foreach ($files as $file) {
                    //图片预览
                    $res = $field['file_preview'] ? '<img src="'.$file['url'].'" style="max-width: 100%;max-height: 400px;">' : '';
                    $re .= $res.'<div class="upload-pre-file"><i class="fa fa-paperclip"></i> <a target="_blank" href="'.$file['url'].'">'.$file['name'].'</a><a href="'.$file['url'].'" target="_blank" class="btn btn-sm btn-primary"><i class="fa fa-search"></i> 预览</a></div>';
                }
            }
        }
    }else{
        $re = $val;
    }
    if ($field['linkview']) {
        $linkview = $field['linkview'];
        $url = str_replace('{$work_id}', $val,$linkview['url']);
        $url = handleAttributeValue($url, $data);
        $url = str_replace('{', "", $url);
        $url = str_replace('}', "", $url);
        $width = $linkview['width'] ? $linkview['width'] : 900;
        $re = ' <a  data-url="'.U($url).'" data-id="dialog-mask-view" data-width="'.$width.'" data-toggle="modal"> '.$re.'</a>';
    }
    return $re;
}

/**
 * 字段类型为function时 根据条件字段显示该表相关信息
 * 为select显示形式
 * @param  $string
 * @param  array $data
 * @return mixed
 */
function parse_function_field($string, $data=array()){
    $execute = handleAttributeValue($string, $data);
    //未匹配变量替换为0
    preg_match_all('/{\$(.*)}/U', $execute, $pars);
    if ($pars[0]) {
        foreach ($pars[0] as $value) {
            $execute = str_replace($value, 0, $execute);
        }
    }
    $arr = parse_field_attr($execute);
    return execute($arr['name'], $arr['vars']);
}

/**
 * 提成规则单独处理
 * @param $value
 * @param array $data
 * @return mixed
 */
function handleAttributeValueTicheng($value, $data=array()){
    $value = str_replace('{$self}', getuserid(), $value);
    $params = returnGets();
    //获取所有参数
    preg_match_all('/{\$(.*)}/U', $value, $pars);
    $pars[1] = array_unique($pars[1]);
    foreach ($pars[1] as $keys) {
        $find_value = findMulArrayKey($params, $keys);
        //以传入数据为主，之后再验证表单数据
        $valus = (in_array($keys,$data) || (isset($data[$keys]))) ? $data[$keys] : ($find_value ? $find_value : false);
        if($valus!==false) {
             //目的为查找流程单中是否包含某一项产品 流程单包含的产品ID
            $valus = is_array($valus) ? 'array('.arr2str($valus,',').')' : $valus;                   
            $valus = !(trim($valus)) ? "''" : $valus;
            $value = str_replace('{$'.$keys.'}',$valus,$value);
        }
    }
    return $value;
}

/**
 * 取出$table中$field为$id的记录
 * @param $table
 * @param $id
 * @param string $field
 * @return mixed|string
 */
function getDataById($table, $id, $field=""){
    $vo = getRedis($table, $id, $field);
    return $vo;
}

/**
 * 保存SQL
 * @param $process_id
 * @param $sql
 */
function setListSql($process_id=0, $sql){
    $cookie_name = $process_id ? "sql_".$process_id : "sql";
    cookie($cookie_name, $sql, 3600*8);
}

/**
 * 解析列表定义规则
 * @param $data
 * @param $grid
 * @param $before_data
 */
function get_list_field($data, $grid, $process_id, $before_data){
    $grid['auth'] = $grid['auth'] ?? '';
    $writablefields = '';
    $before_data = $before_data ?? $data;
    $data['type'] = $data['type'] ?? '';
    
    // 获取当前字段数据
    foreach ($grid['field'] as $field) {
        $array = explode('|', $field);
        $temp  = $data[$array[0]];
        // 函数支持
        if (isset($array[1])) {
            $temp = call_user_func($array[1], $before_data[$array[0]]);
        }
        $data2[$array[0]] = $temp;
        //$data2[$array[0]] = $before_data[$array[0]];
    }
    if (!empty($grid['format'])) {
        $value = preg_replace_callback('/\[([a-z_]+)\]/', function($match) use($data2){return $data2[$match[1]];}, $grid['format']);
    } else {
        $value = implode(' ', $data2);
    }
    // 链接支持
    if ('title'==$grid['field'][0] && '目录'==$data['type']) {
        // 目录类型自动设置子文档列表链接
        $grid['href'] = '[LIST]';
    }
    if (!empty($grid['href'])) {
        //审核值
        if ($grid['auth']=="audit" || $grid['auth']=="change" || strpos($grid['href'], "[CHANGE]")!==false) {
            $nodeInfo = getDataById('processnode',$data['node_id']);
            $writablefields = parse_field_attr($nodeInfo['writablefields']);
        }
        if ($grid['auth']=="change" || strpos($grid['href'], "[CHANGE]")!==false) {
            //派单 列表操作保存
            $auth_groups = ",".userGroupList().","; //所有角色集合
            if ($data && (strpos($data['action_uids'], ','.session('uid').',')!==false || strpos($auth_groups, ','.$data['action_role'].',')!==false)) {
                //当前用户审核操作
                $nodeId = $data['node_id'];
            } else {
                $nodeId = $data['pre_node_id'];
            }
            $nodeInfo = getDataById('processnode', $nodeId);
            $writablefields = parse_field_attr($nodeInfo['writablefields']);
        }
        $links = explode(',',$grid['href']);
        $attribute_par = $pref = "";
        $isonbtn = false;
        $ischecked = "";
        $onoffa = ""; //是否需要开关按钮模式
        if (isset($grid['attribute'])) {
            //需要更新字段信息
            $attribute = explode('|', $grid['attribute']);
            if ($before_data[$attribute[0]]==$attribute[1]) {
                $attribute[1] = 0;
            }
            $attribute_par = "&".$attribute[0]."=".$attribute[1];
            $attribute_data = parse_field_condition_attr($grid['attribute'], '=');
            $attribute_keys = arr2str(array_keys($attribute_data), "|");
            if (strpos($grid['attribute'], "onoff")!==false) {
                if($attribute[1]==1){
                    $ischecked = " checked";
                }
                $onoffa = $attribute[0];
                //使用开头按钮模式
                $isonbtn = true;                  
            }
        }
        
        foreach ($links as $link) {
            $array = explode('|', $link);
            $href  = $array[0];
            if (preg_match('/^\[([a-z_]+)\]$/',$href,$matches)) {
                $val[] = $data2[$matches[1]];
            } elseif ($href=='[PROCESS]') {
                //流程流转
                $pattern2 = '/data-process\=\"(.*)\"/U';
                preg_match_all($pattern2, $data['process'], $process);
                $pattern2 = '/data-action\=\"(.*)\"/U';
                preg_match_all($pattern2, $data['process'], $actions);
                $process_data = str2arr($data['process'], '</a>');
                foreach ($process_data as $keys => $process_a) {
                    if (authcheck('Home/process/'.$actions[1][$keys],session('uid'),1, 'url', 'or', array(),$process[1][$keys]) && ($actions[1][$keys]=="index" || ($actions[1][$keys]=="add") || maxpower())) {
                        $val[] = $process_a."</a>";
                    }
                }
            } else {
                $show = isset($array[1]) ? $array[1] : $value;
                
                // 替换数据变量
                $atype = strtolower(trim(trim($array[0],'['),']'));
                $aprocess_id = $process_id>0 ? '/process_id/'.$process_id : '';
                $href = '/'.strtolower(app('request')->controller()).'/'.$atype.'/id/'.$data['id'].$aprocess_id.'.html';
                
                if ($array[0]=="[VIEW]") {
                    //弹框形式显示
                    $width = isset($array[2]) ? $array[2] : 900;
                    $val[] = '<a data-url="'.$href.'" data-id="dialog-mask-view" data-width="'.$width.'" data-toggle="modal" class="btn btn-sm btn-default view"><i class="fa fa-eye"></i> '.$show.'</a>';
                } elseif ($array[0]=="[DELETE]") {
                    $val[] = '<a data-url="'.$href.'" data-type="get" data-toggle="doajax" data-confirm-msg="确定要继续操作吗？" class="btn btn-outline btn-sm btn-default red"><i class="fa fa-times"></i> '.$show.'</a>';
                } elseif ($array[0]=="[HANDLE]") {
                    if ($isonbtn) {
                        //使用开头按钮模式
                        $val[]  = '<input type="checkbox" '.$ischecked.' class="make-switch" data-size="normal" value="1" data-on-text="开" data-off-text="关" onchange="$(\'#'.$onoffa.$data['id'].'\').trigger(\'click\');">';
                        $val[] .= '<a style="display: none;" id="'.$onoffa.$data['id'].'" data-url="'.$href.'" data-type="get" data-toggle="doajax" data-confirm-msg="确定要继续操作吗？" class="btn btn-sm btn-default handle"><i class="fa fa-hand-paper-o"></i> '.$show.'</a>';
                    } else {
                        $val[] = '<a data-url="'.$href.'" data-type="get" data-toggle="doajax" data-confirm-msg="确定要继续操作吗？" class="btn btn-sm btn-default handle"><i class="fa fa-hand-paper-o"></i> '.$show.'</a>';
                    }
                } elseif ($array[0]=="[AUDIT]") {
                    $val[] = '<a href="'.$href.'" class="btn btn-outline btn-sm btn-default blue"><i class="fa fa-check"></i> '.$show.'</a>';
                } elseif ($array[0]=="[EDIT]") {
                    $val[] = '<a href="'.$href.'" class="btn btn-sm btn-default"><i class="fa fa-edit"></i> '.$show.'</a>';
                } elseif ($array[0]=="[CHANGE]") {
                    //列表编辑且流程流转 当前流转时需保存审核状态(audit)的值，默认为当前节点设置的audit的值
                    $change_audit=$attribute_data['audit'] ? $attribute_data['audit'] : $writablefields['audit'];
                    $val[] = '<input type="hidden" name="id" value="'.$data['id'].'"><input type="hidden" name="node_id" value="'.$nodeId.'"><input type="hidden" name="audit" value="'.$change_audit.'"><a href="'.$href.'" class="btn btn-sm btn-default change" data-fields="'.$attribute_keys.'"><i class="fa fa-exchange"></i> '.$show.'</a>';
                } elseif ($array[0]=="[REJECT]") {
                    $val[] = '<a data-url="'.$href.'" data-id="dialog-mask-view" data-width="600" data-toggle="modal" class="btn btn-outline btn-sm btn-default red"><i class="fa fa-backward"></i> '.$show.'</a>';
                } elseif ($array[0]=="[RESET]") {
                    $val[] = '<a data-url="'.$href.'" data-type="get" data-toggle="doajax" data-confirm-msg="确定要重置流程吗？" class="btn btn-outline btn-sm btn-default red-mint"><i class="fa fa-undo"></i> '.$show.'</a>';
                } elseif ($array[0]=="[REFUND]") {
                    $val[] = '<a data-url="'.$href.'" data-type="get" data-toggle="doajax" data-confirm-msg="确定要退款操作吗？" class="btn btn-outline btn-sm btn-default green-steel"><i class="fa fa-money"></i> '.$show.'</a>';
                } elseif ($array[0]=="[REMARK]") {
                    $val[] = '<a data-url="'.$href.'" data-id="dialog-mask-view" data-width="600" data-toggle="modal" class="btn btn-outline btn-sm btn-default"><i class="fa fa-edit"></i> '.$show.'</a>';
                } else
                    $val[] = '<a href="'.$href.'">'.$show.'</a>';
            }
        }
        $value = implode(' ', $val);
    }
    return $value;
}

/**
 * 获取用户名
 * @param $uid
 * @param $field
 * @return string
 */
function gettruename($uid, $field="truename"){
    $userInfo = getDataById("user", $uid, "username");
    if (!$userInfo) {
        return false;
    }
    return returnUserHi($userInfo[$field], $userInfo['hi']);
}

/**
 * 返回有HI标志
 * @param $str
 * @param $hi
 * @return string
 */
function returnUserHi($str, $hi=""){
    $re = $hi ? $str."<a href='baidu://message/?id=".$hi."'><img class='hi_logo' src='/static/image/hi.png'></a>" : $str;
    return '<span>'.$re.'</span>';
}

/**
 * 获取数据表的所有字段
 * @param  string   $db  数据表
 * @return array
 */
function getDbFields($db){
    $table = Db::query("show columns from fw.fw_".$db);
    $re = array();
    foreach ($table as $val) {
        $re[] = $val['Field'];
    }
    return $re;
}

/**
 * 获取登入帐号
 */
function getuserid(){
    return session("uid");
}

/**
 * 最大权限判断
 * @return bool
 */
function maxpower(){
    $name = app('request')->controller().'/'.app('request')->action();
    $name = strtolower($name);
    // 排除管理员和不受权限控制的业务模块
    if (in_array(session('uid'), Config::get('app.admin_is_trator')) || in_array($name, Config::get('app.admin_is_trator_action'))) {
        return true;
    }
    return false;
}

/**
 * 查看所有数据列表权限
 * @param  int  $process_id
 * @return bool
 */
function alllistPower($name="", $process_id=0){
    $alllist = $name ?? strtolower(app('request')->controller()).'/alllist'; 
    if (authcheck($alllist, session('uid'), 1, 'url', 'or', array(), $process_id)) {
        return true;
    }
    return false;
}

/**
 * 查看部门所有数据列表权限
 * @param  int   $process_id
 * @return bool
 */
function deplistPower($name="", $process_id=0){
    $deplist = $name ?? strtolower(app('request')->controller()).'/deplist';
	if (authcheck($deplist, session('uid'), 1, 'url', 'or', array(), $process_id)) {
        return true;
    }
    return false;
}

/**
 * 检测需要动态判断的文档类目有关的权限
 * @return boolean|null
 * 
 * 返回true则表示当前访问有权限
 * 返回false则表示当前访问无权限
 * 返回null则会进入checkRule根据节点授权判断权限
 */
function checkDynamic($name, $data, $uid, $type, $process_id=0){
    $process_id = $process_id ?? input('param.process_id', 0);
    if ($process_id) {
        $auth = new Auth();
        $authList = $auth->getAuthList($uid, $type);
        foreach ($authList as $key=>$rule) {
            if (strpos($rule, "process")!==false) {
                unset($authList[$key]);
            }
        }
        
        $AuthGroupModel = new AuthGroup();
        $process_auth = $AuthGroupModel::getAuthExtendRulesOfExtend(session('uid'), $process_id, 1, 'AUTH_PROCESS_RULES_'.$process_id) ?? array();
        $process_auth = array_merge($authList, $process_auth);
        $actions = explode('/', $name);
        //$action = strtolower($actions[2]);
        $action = strtolower($actions[0]);
        
        //当前节点信息
        $nodeInfo = getDataById('processnode', $data['node_id'] ?? 0);

        switch ($action) {
            case 'index':
            case 'add':
                break;
            case 'edit':
            case 'update':
                if ($nodeInfo['action']!=$action && $data && ($nodeInfo['type']!='start' && $data['audit']!=1) && !in_array('home/process/forceedit',$process_auth)) {
                    //非当前节点控制操作
                    return false;
                }
                if ($data && ((session('uid')!=$data['uid'] && $data['audit']==1) || (strpos($data['action_uids'], ','.session('uid').',')===false && $data['audit']!=1)) && !in_array('process/forceedit', $process_auth)) {
                    //流程节点权限判断 当前节点可操作人
                    return false;
                }
                break;
            case 'audit': //审核 不能审核自己发布的信息
                $auth_groups = ",".userGroupList().","; //所有角色集合
                //不是该节点的操作不允许审核
                if ($nodeInfo['action']!=$action && $data) {
                    return false;
                }
                if ($data && (strpos($data['action_uids'], ','.session('uid').',')===false && strpos($auth_groups, ','.$data['action_role'].',')===false)) {
                    //流程节点权限判断 当前节点可操作人
                    return false;
                }
                //没有审核本人下单权限，不能审核自己发布信息
                if (session('uid')==$data['uid'] && $data && !in_array('home/process/auditself',$process_auth)) {
                    return false;
                }
                break;
        }
        
        if ($process_auth && in_array($name,$process_auth)) {
            //有权限
            return true;
        } else {
            //无权限
            return false;
        }
    }
}

/**
 * 权限判断
 */
function authcheck($name, $uid, $type=1, $mode='url', $relation='or', $data=array(), $process_id=0){
    $process_id = $process_id ?? input('param.process_id');
    $name = strtolower($name);
    if (!maxpower()) {
        $auth = new Auth();
        if (!$auth->check($name, $uid, $type, $mode, $relation) && !$process_id) {
            return false;
        } elseif (checkDynamic($name, $data, $uid, $type, $process_id)===false) {
            return false;
        } else {
            return true;
        }
    } else {
        return true;
    }
}

/**
 * 根据权限显示相关数据
 */
function display($name, $data=array()){
    $name = strtolower($name);
    $uid = session('uid');
    if (!maxpower()) {
        if (!authcheck($name, $uid, $type=1, $mode='url', $relation='or', $data)) {
            return false;
        }
    }
    return true;
}

/**
 * 上传文件保存数据时处理
 * 只保存文件记录ID
 * @param $file_data
 * @return int
 * 
 * TODO:后期可能需要保存文件SIZE
 */
function handleAttibuteFile($file_data){
    $return = false;
    if ($file_data) {
        $file = json_decode(think_decrypt($file_data), true);
        if (!empty($file)) {
            $return = $file['id'];
        } else {
            $return = $file_data;
        }
    }
    return $return;
}

/**
 * 获取Process信息
 * @param $process_id
 * @return mixed
 */
function getProcess(){
    $process = Db::name('process')->where('status', '=', 1)->column('id,relatedtable,relatedtableId,name','id');
    return $process;
}

/**
 * 获取Process信息
 * @param $process_id
 * @return mixed
 */
function getProcessById($process_id){
    $map = array('status'=>1, 'id'=>$process_id);
    $process = Db::name('process')->where($map)->find();
    return $process;
}

/**
 * 流程所有节点信息
 * @param $process_id
 * @return mixed
 */
function ProcessNodeByprocid($process_id){
    $processNodes=S('PROCESSNODES_PROCID_'.$process_id);
    if(!$processNodes){
        $map['process_id']=$process_id;
        $processNodes=M('Processnode')->where($map)->getField('id,type,noderule,writablefields,actionrule,role');
        S('PROCESSNODES_PROCID_'.$process_id,$processNodes);
    }
    return $processNodes;
}

/**
 * 流程各入口必需参数判断
 * @param $str
 * @param string $action
 */
function handleMustFields($str, $action="add"){
    if (isset($str)) {
        $arr = str2arr($str, '|');
        foreach ($arr as $strs) {
            $marrs = array();
            $marr = str2arr($strs, ":");
            $marrs[$marr[0]] = $marr[1];
            if ($marrs[$action]) {
                $mustFields = str2arr($marrs[$action], ',');
                foreach ($mustFields as $field) {
                    $value = input('param.'.$field);
                    if (!$value) {
                        return false;
                    }
                }
            }
        }
    }
}

/**
 * 用户所有角色集合
 * @param int $uid
 * @return string
 */
function userGroupList($uid=0){
    $uid = $uid ?? session("uid");
    return arr2str(array_column($_SESSION['_GROUP_LIST_'.$uid], 'group_id'), ',');
}

/**
 * 数组转换为字符串，主要用于把分隔符调整到第二个参数
 * @param  array  $arr  要连接的数组
 * @param  string $glue 分割符
 * @return string
 */
function arr2str($arr, $glue=','){
    return $arr ? implode($glue, $arr) : '';
}

/**
 * 用于MODEL create补全时使用，无值时返回false,不影响原有值
 * @param $arr
 * @param string $glue
 * @return bool|string
 */
function arr2str2($arr, $glue=','){
    return $arr ? implode($glue, $arr) : false;
}

function mergeTaskData(&$value, $key, $param){
    $data = getRedis($param, $value['work_id']);
    if ($data) {
        $value = array_merge($value, $data);
    }
}

/**
 * 多维数组统一添加元素
 */
function addkey(&$val, $key, $param){
    $val = array_merge($val,$param);
}

/**
 * 搜索时处理linktable类型有独立搜索条件的属性
 * @param  $extra
 * @return mixed
 */
function handleSearchExtra($extra){
    $extra = str_replace('searchcon', 'condition', $extra);
    return $extra;
}

/**
 * 字段类型为linktable时 根据条件字段显示该表相关信息
 * @param  $key
 * @param  $extra
 * @return string
 */
function linktableValue($key, $extra){
    $rule = parse_field_condition_attr($extra);
    
    //执行数据库操作
    if (strpos($rule['field'], 'concat')!==false) {
        preg_match_all("/(?:\()(.*)(?:\))/i", $rule['field'], $fields);
        $fields = str2arr($fields[1][0],',');
        $rule['field'] = $fields[0];
    }

    $tables = str2arr($rule['table']);
    $table  = $tables[0];
    $data   = getDataById($table, $key, $rule['key']);

    if ($rule['table']=="user"){
        return returnUserHi($data[$rule['field']], $data['hi']);
    } else {
        return $data[$rule['field']];
    }
}

/**
 * 用户所在部门
 * @param  $uid
 * @return bool
 */
function depByUid($uid){
    $user = getDataById('user', $uid, "username");
    if (!$user) {
        return false;
    }
    $dep = getDataById('hr_group', $user['hr_group_id']);
    return $dep['name'];
}

/**
 * 获取员工姓名
 * @param  $uids
 * @return string
 */
function gettruenameByUids($uids){
    $uids = $uids ? str2arr(trim($uids,',')) : '';
    $usernames = '';
    if ($uids) {
        foreach ($uids as $uid) {
            $name = gettruename($uid);
            if ($name) {
                $names[] = $name;
            }
        }
        $usernames = arr2str($names);
    }
    return $usernames;
}

/**
 * 把时间转换成几分钟前、几小时前、几天前
 * @param  $date
 * @return string
 */
function formatTime($timer){
    $str  = '';
    $diff = time() - $timer;
    $day  = floor($diff / 86400);
    $free = $diff % 86400;
    if ($day>0) {
        if ($day>1) {
            return date('Y-m-d H:i', $timer);
        }
        return $day."天前";
    } else {
        if ($free>0) {
            $hour = floor($free / 3600);
            $free = $free % 3600;
            if ($hour>0) {
                return $hour."小时前";
            } else {
                if ($free>0) {
                    $min  = floor($free / 60);
                    $free = $free % 60;
                    if ($min>0) {
                        return $min."分钟前";
                    } else {
                        if ($free>0) {
                            return $free."秒前";
                        } else {
                            return '刚刚';
                        }
                    }
                } else {
                    return '刚刚';
                }
            }
        } else {
            return '刚刚';
        }
    }
}

/**
 * linktable类型经过handleAttributeValue函数处理之后还带有变量信息，则把变量信息清空
 * @param  $value
 * @return mixed
 */
function handleAttributeValue($value, $data=array()){
    $value = str_replace('{$self}', getuserid(), $value);
    $params = returnGets();
    
    //获取所有参数
    preg_match_all('/{\$(.*)}/U', $value, $pars);
    $pars[1] = array_unique($pars[1]);
    foreach ($pars[1] as $keys) {
        $find_value = findMulArrayKey($params, $keys);
        $valus = $find_value ?? ((in_array($keys, $data, true) || (isset($data[$keys]))) ? $data[$keys] : false);
        if ($valus!==false) {
            $valus = !(trim($valus)) ? "''" : $valus;
            $value = str_replace('{$'.$keys.'}', $valus, $value);
        }
    }
    
    return $value;
}

function handleLinktableValue($value){
    preg_match_all('/{\$(.*)}/U', $value, $pars);
    $pars[1] = array_unique($pars[1]);
    foreach ($pars[1] as $keys) {
        $valus = "";
        $value = str_replace('{$'.$keys.'}', $valus, $value);
    }
    
    return $value;
}

/**
 * 获取所有GET参数
 * @return array
 */
function returnGets(){
    $params = array();
    if (input('param.')) {
        foreach (input('param.') as $par_key=>$par_value) {
            $params[$par_key] = input('param.'.$par_key);
        }
    }
    return $params;
}

/**
 * 执行类
 */
function execute($name, $vars=array()){
    $array     = explode('/', $name);
    $method    = array_pop($array);
    $classname = array_pop($array);
    $newClass  = new Execute();
    
    if (is_string($vars)) {
        parse_str($vars, $vars);
    }
    
    $callback  = $newClass::$method($vars);
    
    return $callback;
}

/**
 * 返回用户PATH
 * @param  $uid
 * @return mixed
 */
function returnPathByUid($uid){
    return returnFieldByDataId('path', 'user', $uid, 'username');
}

/**
 * 取某一条记录的指定属性值
 * @param  string $refield
 * @param  $table
 * @param  $id
 * @param  string $field
 * @return mixed
 */
function returnFieldByDataId($refield, $table, $id, $field=""){
    $data = getDataById($table, $id, $field);
    return $data[$refield];
}

/**
 * @param  $rules
 * @return array
 */
function handleRule($rules){
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

function getDepOfUids($uids){
    $uids = $uids ? str2arr(trim($uids,',')) : '';
    $return = "";
    if ($uids) {
        $path = array();
        foreach ($uids as $uid) {
            $userInfo = getRedis("user", $uid, "username");
            $userInfo['path'] = $userInfo['path'] ? str2arr(trim($userInfo['path'],',')) : array();
            $path = array_merge($path, $userInfo['path']);
        }
        $path = array_unique($path);
        unset($path[array_search('0', $path)]);
        $return = ','.arr2str($path).',';
    }
    return $return;
}

/**
 * 计算时间段自然天数
 * @param  $startdate
 * @param  $enddate
 * @return float|string
 */
function sumdays($startdate, $enddate){
    $startdate = strlen($startdate)>10 ? strtotime($startdate) : $startdate; //开始时间
    $enddate   = strlen($enddate)>10 ? strtotime($enddate) : $enddate; //结束时间
    
    //计算时差
    $date   = floor(($enddate-$startdate)/86400);
    $hour   = floor(($enddate-$startdate)%86400/3600);
    $minute = floor(($enddate-$startdate)%86400/60);
    
    //不满半天按半天算
    $Tolal = $date;
    if ($date==0 && $hour==0 && $minute>0) {
        //几小时假按半天计算
        $Tolal = 0.5;
    }
    if ($hour<=6 and $hour!=0) {
        $hour = 5;
        $Tolal = $date.'.'.$hour;
    } else if ($hour==9 || ($hour>5 && $hour<9) || $hour>9 || $hour==23) {
        //如果是9小时就是一天,天数加一;
        $Tolal = $date+1;
    }

    return $Tolal;
}

/**
 * 单个审核
 * @param $data
 * @param $process_id
 */
function addAudit($data, $process_id, $auditvalue){
    $work_id = $data['work_id'] ?? $data['id'];
    
    $datas['work_id']    = $work_id;
    $datas['audit']      = $data['audit'] ?? 0;
    $datas['ispass']     = IspassByAudit($datas['audit']); //1：审核通过 2：驳回
    $datas['auditvalue'] = $auditvalue;
    $datas['uid']        = session('uid');
    $datas['status']     = 1;
    $datas['dateline']   = time();
    
    Db::name('audit_'.$process_id)->strict(false)->insert($datas);
}

/**
 * 批量审核
 * @param $dataList
 * @param $process_id
 */
function muliAddAudit($dataList, $process_id){
    foreach ($dataList as &$data) {
        $data['ispass']   = IspassByAudit($data['audit']);
        $data['uid']      = session('uid');
        $data['status']   = 1;
        $data['dateline'] = time();
    }

    Db::name('audit_'.$process_id)->strict(false)->insertAll($dataList);
}

/**
 * 返回是否审核通过状态
 * @param $audit
 * @return int
 */
function IspassByAudit($audit){
    //审核状态为-1时为驳回
    return $audit==-1 ? 0 : 1;
}

/**
 * 多维数组查找元素名对应的值
 * @param  $arr
 * @param  $find_key
 * @return string
 */
function findMulArrayKey($arr, $find_key){
    $value = "";
    array_walk_recursive($arr, function ($item, $key) use ($find_key,&$value) {
        if ($key===$find_key) {
            $value = $item;
        }
    });
    return $value;
}

function handleAttibuteMultiFile($file_data){
    $return = false;
    if ($file_data && is_array($file_data)) {
        $file_ids = array();
        foreach ($file_data as $file) {
            $file_ids[] = handleAttibuteFile($file);
        }
        $file_ids = implode(',', $file_ids);
        $return = $file_ids;
    }

	return $return;
}

/**
 * 验证完成器
 * @param  array  $data 数据
 * @param  string $type add(默认)/eidt...
 * @return int
 */
function validateAuto($data, $type='add'){
    try {
        //自动验证
        validate(validateAuto::class)->check($data); 
        //自动完成       
        $data = validate(validateAuto::class)->auto($data, $type); 
        return $data;
    } catch (ValidateException $e) {
        mtReturn(200, $e->getError(), '');
    }
}

/**
 * 二维数组根据某个值去重
 * @param  array  $arr 数组
 * @param  string $key 去重的val
 * @return array
 */
function assoc_unique($arr, $key){
    $tmp_arr = array();
    foreach ($arr as $k => $v) {
        if (in_array($v[$key], $tmp_arr)) {
            unset($arr[$k]);
        } else {
            $tmp_arr[] = $v[$key];
        }
    }
    sort($arr);
    return $arr;
}
