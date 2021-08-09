<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用地址
    'app_host'         => env('app.host', ''),
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    'app_map'          => [],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => [],

    // 异常页面的模板文件
    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => false,
    // 文件上传相关配置
    'download_upload' => [
        'mimes'    => '', //允许上传的文件MiMe类型
        'maxSize'  => 50*1024*1024, //上传的文件大小限制 (0-不做限制)
        'exts'     => 'jpg,gif,png,bmp,jpeg,zip,rar,tar,gz,7z,doc,docx,txt,xml,xlsx,xls,csv,wav,ppt', //允许上传的文件后缀
        'autoSub'  => true, //自动子目录保存文件
        'subName'  => array('date', 'Y-m-d'), //子目录创建方式，[0]-函数名，[1]-参数，多个参数使用数组
        'rootPath' => './Uploads/File/', //保存根路径
        'savePath' => '', //保存路径
        'saveName' => array('uniqid', ''), //上传文件命名规则，[0]-函数名，[1]-参数，多个参数使用数组
        'saveExt'  => '', //文件保存后缀，空则使用原后缀
        'replace'  => false, //存在同名是否覆盖
        'hash'     => true, //是否生成hash编码
        'callback' => false, //检测文件是否存在回调函数，如果存在返回文件信息数组
    ],
    // 文件上传方式
    'file_upload_type'       =>  'Local',   
    // 审核状态
    'model_array'            => array('model', 'repeater_model'), 
    // 管理员帐号
    'admin_is_trator'        => array('15728', '12706'),
    // 不受权限影响
    'admin_is_trator_action' => [
        //...
    ],
    // 休息日文件保存
    'restdays'               => '/restday.token',
    //1:事假|2:婚假|3:病假|4:年假|5:产假|6:哺乳假|7:产检假|8:小产假|9:陪产假|10:丧假
	//行政系统请假类型计算请假时长是否含周末(法定休息日) 0：不含周末 1：含周末
	'leaveweekend'           => array(1=>0, 2=>0, 3=>0, 4=>0, 5=>1, 6=>1, 7=>0, 8=>1, 9=>1, 10=>0, 11=>0, 12=>1),
];
