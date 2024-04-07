<?php

namespace Proto2\Helper;

class DocGenerator {

	protected $generator;

	protected $fileTypes = [
		'doc' => 'Word2007',
		'docx' => 'Word2007',
		'html' => 'HTML',
		'pdf' => 'PDF',
		'rtf' => 'RTF',
	];

	protected $selfCloseTags = [
		'area',
		'base',
		'br',
		'col',
		'embed',
		'hr',
		'img',
		'input',
		'keygen',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',	
	];

	public function __construct(array $opts=[]) {
		//loop through opts
		foreach($opts as $k => $v) {
			//set property?
			if(property_exists($this, $k)) {
				$this->$k = $v;
			}
		}
	}

	public function generator() {
		//create service?
		if(!$this->generator) {
			$this->generator = new \PhpOffice\PhpWord\PhpWord;
		}
		//return
		return $this->generator;
	}

	public function html($html) {
		//format html
		$html = preg_replace('/<(' . implode('|', $this->selfCloseTags) . ')(.*)>/U', '<$1$2/>', $html);
		$html = str_replace('//>', '/>', $html);
		//add section
		$section = $this->generator()->addSection();
		//inject html
		\PhpOffice\PhpWord\Shared\Html::addHtml($section, $html);
		//chain it
		return $this;
	}

	public function string($ext) {
		//buffer
		ob_start();
		//save to php://output
		$this->save('php://output', $ext);
		//return
		return ob_get_clean();
	}

	public function save($filePath, $ext='') {
		//get file extension
		$ext = $ext ?: strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
		//valid file type?
		if(!isset($this->fileTypes[$ext])) {
			throw new \Exception("Invalid file type: $ext");
		}
		//create object writer
		$class = 'PhpOffice\\PhpWord\\Writer\\' . $this->fileTypes[$ext];
		$objWriter = new $class($this->generator());
		//save file
		$objWriter->save($filePath);
		//return
		return $objWriter;
	}

	public function download($fileName) {
		//set headers
		header("Content-Type: application/octet-stream"); 
		header("Content-Disposition: attachment; filename=\"$fileName\"");
		//get file extension
		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		//save to php://output
		$this->save('php://output', $ext);
		exit();
	}

}