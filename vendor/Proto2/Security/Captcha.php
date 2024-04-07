<?php

namespace Proto2\Security;

class Captcha {

	protected $code = '';
	protected $fontPath = '';

	protected $crypt = null;
	protected $session = null;

	public function __construct(array $opts=[], $merge=true) {
		//set opts
		foreach($opts as $k => $v) {
			//property exists?
			if(property_exists($this, $k)) {
				//is array?
				if(!$merge || !is_array($this->$k) || !is_array($v)) {
					$this->$k = $v;
					continue;
				}
				//loop through array
				foreach($v as $a => $b) {
					$this->$k[$a] = $b;
				}
			}
		}
	}

	public function isValid($input) {
		return $input && strcasecmp($input, $this->session->get('captcha')) == 0;
	}

	public function render(array $opts=array()) {
		//set opts
		$opts = array_merge(array(
			'format' => 'jpeg',
			'raw' => false,
			'reuse' => false,
			'length' => 6,
			'width' => 200,
			'height' => 50,
			'lines' => 0,
			'pixels' => 8,
			'capitalise' => true,
			'exclude' => [ '0', 'o', 'O', '1', 'i', 'I', 'l' ],
		), $opts);
		//reuse code?
		if($opts['reuse']) {
			$this->code = $this->session->get('captcha');
		}
		//generate code?
		if(!$this->code) {
			//generate code
			$this->code = $this->crypt->nonce(32);
			$this->code = str_replace($opts['exclude'], '', $this->code);
			$this->code = substr($this->code, 0, $opts['length']);
			//capitalise?
			if($opts['capitalise']) {
				$this->code = strtoupper($this->code);
			}
			//save to session
			$this->session->set('captcha', $this->code);
		}
		//setup image
		$image = imagecreatetruecolor($opts['width'], $opts['height']);       
		$bckColour = imagecolorallocate($image, 255, 255, 255); //white
		$lineColour = imagecolorallocate($image, 96, 96, 96); //dark grey
		$pixelColour = imagecolorallocate($image, 0, 0, 255); //dark blue
		$fontColour = imagecolorallocate($image, 0, 0, 0); //black
		$fontPath = $this->fontPath ?: __DIR__ . '/fonts/arial.ttf';
		$isFont = is_file($fontPath);
		//create background
		imagefilledrectangle($image, 0, 0, $opts['width'], $opts['height'], $bckColour);
		//add lines?
		if($isFont && $opts['lines']) {
			for($i = 0, $l = mt_rand($opts['lines']-1, $opts['lines']+1); $i < $l; $i++) {
				imageline($image, 0, mt_rand()%50, 250, mt_rand()%50, $lineColour);
			}
		}
		//add random pixels
		for($i = 0, $l = ($opts['width'] * $opts['pixels']); $i < $l; $i++) {
			imagesetpixel($image, mt_rand()%$opts['width'], mt_rand()%$opts['height'], $pixelColour);
		}
		//add text
		for($i=0, $l = strlen($this->code); $i < $l; $i++) {
			//set default X and Y coordinates
			$x = 10 + (($opts['width'] / $opts['length']) * $i);
			$y = ($opts['height'] / 2) - 7;
			//use true type?
			if($isFont) {
				imagettftext($image, 18, 0, $x-5, $y+15, $fontColour, $fontPath, $this->code[$i]);
			} else {
				imagestring($image, 5, $x, $y, $this->code[$i], $fontColour);
			}
		}
		//get function
		$func = 'image' . $opts['format'];
		//encode to base64?
		if($opts['raw']) {
			ob_start();
			$func($image);
			return ob_get_clean();
		}
		//display as image file
		header('Content-type: image/' . $opts['format']);
		$func($image);
	}

}