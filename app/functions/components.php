<?php
// 工具函数

use think\facade\Db;

/**
 * 发送短信
 * @param  string|array    $mobile   手机号码
 * @param  string          $content  短信内容
 * @return array
 */
function sendMessage($mobile, $content){
    $title = '【江西华邦】';
    $url   = "http://sms-api.luosimao.com/v1/send.json";
    $key   = 'api:key-deac6c6c90c92e161d0e54f8f9f5491b';
    $error = [
        '-10' => '验证信息失败',
        '-11' => '用户接口被禁用',
        '-20' => '短信余额不足',
        '-30' => '短信内容为空',
        '-31' => '短信内容存在敏感词',
        '-32' => '短信内容缺少签名信息',
        '-33' => '短信过长，超过300字',
        '-34' => '签名不可用',
        '-40' => '错误的手机号',
        '-41' => '号码在黑名单中',
        '-42' => '验证码类短信发送频率过快',
        '-50' => '请求发送IP不在白名单内',
    ];
    
    $ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0 );
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_HEADER, FALSE);
	curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	curl_setopt($ch, CURLOPT_USERPWD, $key);
	curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array('mobile'=>$mobile, 'message'=>$content.$title));
    
    $res  = json_decode(curl_exec($ch));
    $info = $res->error ? $mobile.' -- 发送失败,'.$error[$res->error].'</br>' : $mobile.' -- 发送成功</br>';
    curl_close($ch);
    
    return $info;	
}

/**
 * 发送微信模版消息 (江西华邦服务中心公众号)
 * @param   string   $openid   发送对象
 * @param   int      $mubanid  模版编号
 * @param   array    $info     内容
 * @param   array    $cs       参数(可选)
 * @return  mixed
 */
function sendWxMessage($openid, $mubanid, $info=[], $cs=[]){
    $url  = "http://erp.appjx.cn/fwzx/send_msg.php";
    $data = array('openid'=>$openid, 'mubanid'=>$mubanid, 'data'=>$info, 'cs'=>$cs);
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2); //不再接收1
    curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_AUTOREFERER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    
    $tmpInfo = curl_exec($curl);
    
    if (curl_errno($curl)) $error = '错误：'.curl_error($curl);
    
    try {
        $res = $tmpInfo=='msg is ok!' ? '发送成功' : '发送失败，'.$error;
        curl_close($curl);
        return $res;
    } catch (Exception $e) {
        $error = $e->getMessage();
        $res = $tmpInfo=='msg is ok!' ? '发送成功' : '发送失败，'.$error;
        curl_close($curl);
        return $res;
    }
}

/**
 * 提示窗
 * @param  int         $status    代号
 * @param  string      $info      提示内容
 * @param  string      $url       重定向跳转地址
 * @param  int         $time      显示时长
 * @return string
 */
function mtReturn($status=200, $info, $url, $time=6000){
    header("Content-Type:text/html; charset=utf-8");
    
    $result = array();
    $result['statusCode'] = $status;
    $result['message']    = $info;
    $result['url']        = $url;
    $result['time']       = $time;
    
    exit(json_encode($result));
}

/**
 * 分页
 * @param  int    $count  条数
 * @param  array  $field  列表查询字段（列）
 * @return array
 */
function page($count, $field=[]){
    $records["recordsTotal"]       =  $count;
    $records["recordsFiltered"]    =  $count;
    $records["draw"]               =  intval(input('param.draw'));
    $records["length"]             =  intval(input('param.length')) < 0 ? $count : intval(input('param.length'));
    $records["start"]              =  intval(input('param.start'));
    $records['order'][0]['column'] =  input('param.order')[0]['column'];
    $records['order'][0]['dir']    =  input('param.order')[0]['dir'];
    $records['dir']                =  $records['order'][0]['dir'];
    
    if ($field) {
        $index = intval($records['order'][0]['column'])<count($field) ? intval($records['order'][0]['column']) : 0;
        $records['column'] = $field[$index];
    } else {
        $records['column'] = $records['order'][0]['column'];
    }

    return $records;
}

/**
 * 保存操作日志-1 自动保存
 * @param  string  $action  页面标题
 */
function autoInseruserLog($action){
    $notControls = array("userlog");
    $controlname = strtolower(app('request')->controller());
    $actionname  = strtolower(app('request')->action());
    
    if (((input('param.length') && input('param.draw') && $actionname=="index") || $actionname!="index") && !in_array($controlname, $notControls)) {
        $addLog['uid']      = getuserid(); //操作人
        $addLog['dateline'] = time(); //操作时间
        $addLog['action']   = $action ?? "页面访问"; //页面标题
        $addLog['url']      = $_SERVER['PHP_SELF']!="/index.php" ? $_SERVER['PHP_SELF'] : $_SERVER['PHP_SELF']."?".$_SERVER["QUERY_STRING"]; //页面地址
        $addLog['IP']       = get_client_ip(); //ip
        $addLog['remark']   = setSearchCookie(1) ? json_encode(setSearchCookie(1), JSON_UNESCAPED_UNICODE) : ""; //数据包  
        
        //海量日志数据存储到mongodb，放弃用mysql存储日志数据
        Db::name('user_log')->insert($addLog);
    }
}

/**
 * 保存操作日志-2 针对log()方法的补充
 * 在特殊情况下系统无法获取用户操作时，可用此方法记录用户操作
 * @param  array  $data  用户操作数据
 */
function insertUserLog($data){
    $addLog['uid']      = getuserid();     //操作人
    $addLog['dateline'] = time();          //操作时间
    $addLog['action']   = $data['action']; //页面标题
    $addLog['url']      = $_SERVER['DOCUMENT_URI'].'?'.$_SERVER['QUERY_STRING']; //页面地址
    $addLog['IP']       = get_client_ip(); //ip
    $addLog['remark']   = $data['remark']; //数据包
    
    //海量日志数据存储到mongodb，放弃用mysql存储日志数据
    Db::name('user_log')->insert($addLog);
}

/**
 * 数据导出到Excel
 * @access protected
 * @param  string         $filename    导出表格名称
 * @param  array          $headArr     表头
 * @param  array          $list        导出数据
 */
function xlsout($filename='数据表', $headArr, $list){
    require_once '../vendor/PHPExcel.class.php';
    require_once '../vendor/PHPExcel/Writer/Excel5.php';
    require_once '../vendor/PHPExcel/IOFactory.php';
    
    getExcel($filename, $headArr, $list);
}

function getExcel($fileName, $headArr, $data){
    //对数据进行检验
    if (empty($data) || !is_array($data)) die("data must be a array");
    
    //检查文件名
    if (empty($fileName)) exit;
    
    $date      = date("Y_m_d",time());
    $fileName .= "_{$date}.xls";
    
    //创建PHPExcel对象，注意，不能少了\
    $objPHPExcel = new \PHPExcel();
    $objProps    = $objPHPExcel->getProperties();
    $key         = 0;
    
    foreach($headArr as $v){
        //注意，不能少了。将列数字转换为字母\
        $colum = \PHPExcel_Cell::stringFromColumnIndex($key);
        $objPHPExcel->setActiveSheetIndex(0) ->setCellValue($colum.'1', $v);
        $key += 1;
    }
    
    $column = 2;
    $objActSheet = $objPHPExcel->getActiveSheet();
    
    foreach ($data as $key => $rows) { //行写入
        $span = 0;
        foreach ($rows as $keyName=>$value) {// 列写入
            $j = \PHPExcel_Cell::stringFromColumnIndex($span);
            $objActSheet->setCellValue($j.$column, $value);
            $span++;
        }
        $column++;
    }
    
    $fileName = iconv("utf-8", "gb2312", $fileName);
    $objPHPExcel->setActiveSheetIndex(0);
    
    ob_end_clean();//清除缓冲区,避免乱码
    header('Content-Type: application/vnd.ms-excel');
    header("Content-Disposition: attachment;filename=\"$fileName\"");
    header('Cache-Control: max-age=0');
    
    $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
    $objWriter->save('php://output'); //文件通过浏览器下载
    
    exit;
}

/**
 * Excel数据导入
 * @param  string  $filename  导入文件路径
 */
function xlsin($filename){
    require_once '../vendor/PHPExcel.class.php';
    require_once '../vendor/PHPExcel/CachedObjectStorageFactory.php';
    require_once '../vendor/PHPExcel/Settings.php';

    $cacheMethod = \PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
    $cacheSettings = array('memoryCacheSize'=>'8MB');
    \PHPExcel_Settings::setCacheStorageMethod($cacheMethod,$cacheSettings);
    
    $postfixs = explode('.', $filename);
    $postfix  = $postfixs[count($postfixs)-1];
    $PHPExcel = new \PHPExcel();
    
    //如果excel文件后缀名为.xls，导入这个类
    if ($postfix=="xls") {
        require_once '../vendor/PHPExcel/Reader/Excel5.php';
        $PHPReader = new \PHPExcel_Reader_Excel5();
    } else {
        //如果excel文件后缀名为.xlsx，导入这下类
        require_once '../vendor/PHPExcel/Reader/Excel2007.php';
        $PHPReader = new \PHPExcel_Reader_Excel2007();
    }
    
    $PHPReader->setReadDataOnly(true);
    //载入文件
    $PHPExcel = $PHPReader->load($filename);
    //获取表中的第一个工作表，如果要获取第二个，把0改为1，依次类推
    $currentSheet = $PHPExcel->getSheet(0);
    //获取总列数
    $allColumn = $currentSheet->getHighestColumn();
    //获取总行数
    $allRow = $currentSheet->getHighestRow();
    //循环获取表中的数据，$currentRow表示当前行，从哪行开始读取数据，索引值从0开始
    $arr = array();
    $allColumns = '';
    
    //据表头取出最后一列 表头没有值的列即为最后一列
    for ($currentRow=1; $currentRow<=$allRow; $currentRow++) {
        //从哪列开始，A表示第一列
        for ($currentColumn='A'; $currentColumn<=$allColumn; $currentColumn++) {
            //数据坐标
            $address = $currentColumn.$currentRow;
            if ($currentRow==1 && $currentSheet->getCell($address)->getValue()) {
                $allColumns = $currentColumn;
            } else {
                break;
            }
        }
    }
    
    $allColumn = $allColumns;
    
    for ($currentRow=2; $currentRow<=$allRow; $currentRow++) {
        //从哪列开始，A表示第一列
        for ($currentColumn='A'; $currentColumn<=$allColumn; $currentColumn++) {
            //数据坐标
            $address = $currentColumn.$currentRow; //var_dump($currentSheet->getCell($address)->getValue());exit;
            if (!$currentSheet->getCell($address)->getValue() && $currentColumn=='A') {
                break;
            }
            //读取到的数据，保存到数组$arr中
            $arr[$currentRow][$currentColumn] = $currentSheet->getCell($address)->getValue();
        }
    }
    
    return $arr;
}

/**
 * 页面初始化
 */
function initPage($pageTitle, $redirectUrl){
    // 验证登入
    if (!session('?uid')) 
        return redirect('/publics/login.html')->send();
    
    // 保存操作日志
    autoInseruserLog($pageTitle);

    // 验证权限
    $name = app('request')->controller().'/'.app('request')->action();
    if (!authcheck($name, session('uid'))) 
        mtReturn(300, session('uid').'很抱歉，此项操作您没有权限！', $redirectUrl);
}

/**
 * 判断用户设备是pc端还是移动端
 */
function isMobile(){
    //获取USER AGENT
    $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
    //分析数据
    $is_pc      = (strpos($agent, 'windows nt')) ? true : false;
    $is_mac     = (strpos($agent, 'mac os')) ? true : false;
    $is_iphone  = (strpos($agent, 'iphone')) ? true : false;
    $is_ipad    = (strpos($agent, 'ipad')) ? true : false;
    $is_android = (strpos($agent, 'android')) ? true : false;
    //输出数据
    if ($is_iphone || $is_android) 
        return  true;
    return false;
}
