<?php
namespace app\validate;

use think\Validate;
use think\facade\Db;

class validateAuto extends Validate
{
    // 验证规则
    protected $rule = [];
    
    // 验证错误提示
    protected $message = [];
    
    /**
     * 初始化定义数据表
     */
    public function __construct()
    {
        if (input('param.process_id')) {
            
            $process = getProcessById(input('param.process_id'));
            
            $this->db      = Db::name(trim($process['relatedtable']));
            $this->modelId = $process['relatedtableId'];
            $this->fields  = getAttribute($process['relatedtableId'], true);
            
            $this->checkAttribute();
        }
    }

    /**
     * 检测属性的自动验证
     */
    protected function checkAttribute()
    {
        foreach ($this->fields as $attr) {
            if ($attr['ismust']==1 && $attr['isshow']==1 && $attr['is_hidden']==0 && empty($attr['validate_rule'])) {
                // 验证必填字段require代替isEmpty,
                $this->rule[$attr['name']] = 'require';
                $this->message[$attr['name'].'.require'] = $attr['title'].'不能为空';
            } elseif (!empty($attr['validate_rule'])) {
                // 自定义验证规则
                $this->rule[$attr['name']] = $attr['validate_rule'];
                $this->message[$attr['name'].'.'.$attr['validate_rule']] = $attr['title'].$attr['validate_error'];
            }
        }
    }

    /**
     * 检测属性的自动完成
     */
    public function auto($data, $type)
    {
        foreach ($this->fields as $attr) {
            // 自动完成规则
            if (!empty($attr['auto_rule'])) {
                $auto[$attr['name']] = $attr['auto_rule'];
            } elseif ($attr['type']=='checkbox' || ($attr['is_multiple']==1 && $attr['type']=='linktable')) { //多选型
                $auto[$attr['name']] = 'arr2str2';
            } elseif ($attr['type']=='datetime' || $attr['type']=='date' || $attr['type']=='datem'|| $attr['type']=='daterange' || $attr['type']=='daterangetime') { //日期型
                $auto[$attr['name']] = 'strtotime';
            } elseif ($attr['type']=='editor') { //编辑器
                $auto[$attr['name']] = 'html_entity_decode';
            } elseif ($attr['type']=='file') { //文件上传
                $auto[$attr['name']] = 'handleAttibuteFile';
            } elseif ($attr['type']=='multifile') { //多文件上传
                $auto[$attr['name']] = 'handleAttibuteMultiFile';
            }
        }
        
        // 执行自动完成函数 （? 字段存在表单中 : 字段不存在表单中）
        foreach ($auto as $fieldName=>$functionName) {
            $data[$fieldName] = array_key_exists($fieldName, $data) ? $functionName($data[$fieldName]) : $functionName($data);
        }
        
        // 自动补全
        if ($type=='add') {
            $data['uid']      = session('uid');
            $data['status']   = 1;
            $data['dateline'] = time();
        } else {
            $data['updatetime'] = time();
        }
        
        return $data;
    }

    /**
     * 自定义验证规则 通用
     * 验证规则：字段不能为空
     */
    protected function isEmpty($value, $rule, $data=[])
    {
        return $rule = empty($value) || $value==''  ? false : true;
    }

    /**
     * 自定义验证规则
     * attribute: timeto 请假结束
     * 验证规则：请假结束时间不能大于请假开始时间
     */
    protected function validateQjdate($value, $rule, $data=[])
    {
        $startTime = strtotime($data['timefrom']);
        $endTime   = strtotime($value);
        
        return $rule = $endTime>$startTime ? true : '请假结束时间不能大于请假开始时间';
    }
}
