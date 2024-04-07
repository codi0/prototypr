<?php

namespace Proto2\Cache;

class File extends AbstractCache {

	protected $dir = './cache';
	protected $ext = '.cache';
	protected $dirLayers = 2;
	protected $autoGc = 1000;

	public function __construct(array $opts=array()) {
		//call parent
		parent::__construct($opts);
		//format cache dir
		$this->dir = realpath($this->dir);
		$this->dir = str_replace('\\', '/', $this->dir);
		$this->dir = rtrim($this->dir, '/');
	}

	public function has($key) {
		return is_file($this->filePath($key));
	}

	public function get($key, $default=null) {
		//set vars
		$res = $default;
		$file = $this->filePath($key);
		//file exists?
		if(!is_file($file)) {
			return $res;
		}
		//get file contents?
		if($data = file_get_contents($file)) {
			//unserialize
			$data = unserialize($data);
			//has expired?
			if($data['expires'] > 0 && $data['expires'] < time()) {
				unlink($file);
			} else {
				$res = $data['value'];
			}
		}
		//return
		return $res;
	}

	public function set($key, $value, $ttl=null) {
		//set vars
		$res = false;
		$file = $this->filePath($key);
		$dir = dirname($file);
		$expires = $ttl > 0 ? (time() + $ttl) : 0;
		//make dir?
		if(!is_dir($dir)) {
			mkdir($dir, 0770, true);
		}
		//save to file?
		if(file_put_contents($file, serialize(array( 'value' => $value, 'expires' => $expires )), LOCK_EX) > 0) {
			//success
			$res = true;
			//set permission
			@chmod($file, 0660);
		}
		//return
		return $res;
	}

	public function delete($key) {
		//set vars
		$file = $this->filePath($key);
		//return
		return is_file($file) && unlink($file);
	}

	public function clear($dir=null) {
		//set vars
		$dir = $dir ?: $this->dir;
		$search = (array) glob(rtrim($dir, '/') . '/*', GLOB_NOSORT);
		//loop through all items
		foreach($search as $item) {
			//is dir?
			if(is_dir($item)) {
				//recursive
				$this->clear($item);
				//delete dir
				rmdir($item);
			} elseif(stripos($item, '/.htaccess') === false) {
				//delete file
				unlink($item);
			}
		}
		//return
		return true;
	}

	public function gc($dir=null) {
		//set vars
		$dir = $dir ?: $this->dir;
		$search = (array) glob(rtrim($dir, '/') . '/*', GLOB_NOSORT);
		//loop through all items
		foreach($search as $item) {
			//is dir?
			if(is_dir($item)) {
				//recursive
				$this->gc($item);
			} elseif(stripos($item, '/.htaccess') === false) {
				//get file
				$this->get($item);
			}
		}
		//return
		return true;
	}

	protected function filePath($key) {
		//valid key?
		if(!$key || !is_string($key)) {
			throw new \Exception("Invalid cache key");
		}
		//already formatted?
		if(strpos($key, $this->dir) === 0) {
			return $key;
		}
		//set vars
		$parts = array();
		$hash = md5($key);
		//create dir parts
		for($i=0; $i < $this->dirLayers; $i++) {
			$parts[] = $hash[0];
			$hash = substr($hash, 1);
		}
		//return hashed path
		return $this->dir . '/' . ($parts ? implode('/', $parts) . '/' : '') . $hash . $this->ext;
	}

}