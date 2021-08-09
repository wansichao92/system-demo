<?php
namespace app\controller;

use think\facade\Db;
use app\model\File as fileModel;
use think\facade\Config;

class File
{
    public function __construct()
    {
        $this->pageTitle = "File";
    }

    public function upload_fileinput()
    {
        $return = [
            'status'   => 1, 
            'info'     => '上传成功', 
            'data'     => '',
            'returnid' => input('post.sendid')=='' ? 0: input('post.sendid')
        ];
        
        //上传图片加水印
        $addressinfo = '';
        if (input('post.addressinfo')) {
			$addressinfo  = input('post.addressinfo');
			$addressinfo  = str_replace("(", "\n(", $addressinfo);
			$addressinfo  = str_replace("坐标", "\n坐标", $addressinfo);
			$addressinfo .= "\n".date("Y-m-d h:i:s");
        }
        
        $download_upload = Config::get('app.download_upload');
        $fileModel = new fileModel();
        $info = $fileModel->uploadsy($_FILES, $download_upload, '', '', $addressinfo);
        
        if ($info) {
			$return['data']   = think_encrypt(json_encode($info['fileinput']));
            $return['info']   = $info['fileinput']['name'];
            $return['url']    = $info['fileinput']['url'];
            $return['fileid'] = handleAttibuteFile($return['data']);
		} else {
            $return['status'] = 0;
            $return['info']   = $Files->getError();
        }
        
        ob_get_clean();
        return json($return);
	}
}
