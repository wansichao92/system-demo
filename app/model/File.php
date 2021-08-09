<?php
namespace app\model;

use think\Model;

class File extends Model 
{
	/**
	 * 文件模型自动完成
	 * @var array
	 */
	protected $_auto = array(
		//array('create_time', time(), 1),
	);

	/**
	 * 文件模型字段映射
	 * @var array
	 */
	protected $_map = array(
		'type' => 'mime',
	);

	/**
	 * 文件上传
	 * @param  array  $files   要上传的文件列表（通常是$_FILES数组）
	 * @param  array  $setting 文件上传配置
	 * @param  string $driver  上传驱动名称
	 * @param  array  $config  上传驱动配置
	 * @return array           文件上传成功后的信息
	 */
    public function upload($files, $setting, $driver = 'Local', $config = null)
    {
		/* 上传文件 */
        $setting['callback'] = array($this, 'isFile');
        require_once '../../vendor/Upload.class.php';
        $Upload = new \Upload($setting, $driver, $config);
		$info   = $Upload->upload($files);

		/* 设置文件保存位置 */
		$this->_auto[] = array('location', 'Ftp' === $driver ? 1 : 0, 1);

		if($info){ //文件上传成功，记录文件信息
			foreach ($info as $key => &$value) {
                $value['url'] = substr($setting['rootPath'], 1).$value['savepath'].$value['savename'];	//在模板里的url路径
				/* 已经存在文件记录 */
				if(isset($value['id']) && is_numeric($value['id'])){
					$value['path'] = substr($setting['rootPath'], 1).$value['savepath'].$value['savename'];	//在模板里的url路径
					continue;
				}


				/* 记录文件信息 */
				if($this->create($value) && ($id = $this->add())){
					$value['id'] = $id;
				} else {
					//TODO: 文件上传成功，但是记录文件信息失败，需记录日志
					unset($info[$key]);
				}
			}
			return $info; //文件上传成功
		} else {
			$this->error = $Upload->getError();
			return false;
		}
	}
	
    public function uploadsy($files, $setting, $driver='Local', $config=null, $addressinfo=null)
    {
        /* 上传文件 */
        $setting['callback'] = array($this, 'isFile');
        require_once '../vendor/Upload.class.php';
		$Upload = new \Upload($setting, $driver, $config);
		$info   = $Upload->upload($files);
		
		/* 设置文件保存位置 */
		$this->_auto[] = array('location', 'Ftp' === $driver ? 1 : 0, 1);
	
		if($info){ //文件上传成功，记录文件信息
			foreach ($info as $key => &$value) {
				$value['url'] = substr($setting['rootPath'], 1).$value['savepath'].$value['savename'];	//在模板里的url路径
				/* 已经存在文件记录 */
				if(isset($value['id']) && is_numeric($value['id'])){
					$value['path'] = substr($setting['rootPath'], 1).$value['savepath'].$value['savename'];	//在模板里的url路径
					
					continue;
                }
                $value['mime'] = $value['type'];
                
                /* 记录文件信息 */
				if($this->create($value) && ($id = $this->add())){
					//echo 11;die;
					if($addressinfo){
						$bigImgPath = '.'.$value['url'];
						$img = imagecreatefromstring(file_get_contents($bigImgPath));
						list($bgWidth, $bgHight, $bgType) = getimagesize($bigImgPath);
						
						$font = './myziti.ttf';//字体,字体文件需保存到相应文件夹下
						$black = imagecolorallocate($img, 233, 14, 91);//字体颜色 RGB
						
						$fontSize = intval(62*$bgWidth/4000);   //字体大小
						$circleSize = 0; //旋转角度
						$left = 10;      //左边距
						$top = intval(80*$bgWidth/4000);       //顶边距
						imagefttext($img, $fontSize, $circleSize, $left, $top, $black, $font, $addressinfo);
						//不能中文
                        //imagestring($img, 5, 30, 20, 'aadbsdkhfkshdjk', $black);
                        switch ($bgType) {
							case 1: //gif
								header('Content-Type:image/gif');
								imagegif($img,$bigImgPath);
								break;
							case 2: //jpg
								header('Content-Type:image/jpg');
								imagejpeg($img,$bigImgPath);
								break;
							case 3: //png
								header('Content-Type:image/png');
								imagepng($img,$bigImgPath);  //在 images 目录下就会生成一个 circle.png 文件,上面也可设置相应的保存目录及文件名。
								break;
							default:
								break;
						}
						//imagedestroy($img);
					}
					$value['id'] = $id;
				} else {
					//TODO: 文件上传成功，但是记录文件信息失败，需记录日志
					unset($info[$key]);
				}
			}
			return $info; //文件上传成功
		} else {
			$this->error = $Upload->getError();
			return false;
		}
    }
    
	/**
	 * 下载指定文件
	 * @param  number  $root 文件存储根目录
	 * @param  integer $id   文件ID
	 * @param  string   $args     回调函数参数
	 * @return boolean       false-下载失败，否则输出下载文件
	 */
    public function download($root, $id, $callback = null, $args = null)
    {
		/* 获取下载文件信息 */
		$file = $this->find($id);
		if(!$file){
			$this->error = '不存在该文件！';
			return false;
		}

		/* 下载文件 */
		switch ($file['location']) {
			case 0: //下载本地文件
				$file['rootpath'] = $root;
				return $this->downLocalFile($file, $callback, $args);
			case 1: //TODO: 下载远程FTP文件
				break;
			default:
				$this->error = '不支持的文件存储类型！';
				return false;

		}

	}

	/**
	 * 检测当前上传的文件是否已经存在
	 * @param  array   $file 文件上传数组
	 * @return boolean       文件信息， false - 不存在该文件
	 */
    public function isFile($file)
    {
		if(empty($file['md5'])){
			throw new Exception('缺少参数:md5');
		}
		/* 查找文件 */
		$map = array('md5' => $file['md5']);
		return $this->field(true)->where($map)->find();
	}

	/**
	 * 下载本地文件
	 * @param  array    $file     文件信息数组
	 * @param  callable $callback 下载回调函数，一般用于增加下载次数
	 * @param  string   $args     回调函数参数
	 * @return boolean            下载失败返回false
	 */
    private function downLocalFile($file, $callback = null, $args = null)
    {
		if(is_file($file['rootpath'].$file['savepath'].$file['savename'])){
			/* 调用回调函数新增下载数 */
			is_callable($callback) && call_user_func($callback, $args);

			/* 执行下载 */ //TODO: 大文件断点续传
			header("Content-Description: File Transfer");
			header('Content-type: ' . $file['type']);
			header('Content-Length:' . $file['size']);
			if (preg_match('/MSIE/', $_SERVER['HTTP_USER_AGENT'])) { //for IE
				header('Content-Disposition: attachment; filename="' . rawurlencode($file['name']) . '"');
			} else {
				header('Content-Disposition: attachment; filename="' . $file['name'] . '"');
			}
			readfile($file['rootpath'].$file['savepath'].$file['savename']);
			exit;
		} else {
			$this->error = '文件已被删除！';
			return false;
		}
	}
}
