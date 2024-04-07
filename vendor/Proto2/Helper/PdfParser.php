<?php

namespace Proto2\Helper;

class PdfParser {

    protected $executable = 'pdftotext';

    protected $options = [
		'eol' => 'unix',
		'enc' => 'UTF-8',
		'nopgbrk' => null,
		'table' => null,
		'fixed' => 2,
	];

	public function __construct($executable = null, array $options = []) {
		//set executable
		$this->executable = $executable ?: $this->executable;
		//merge options
		foreach($options as $k => $v) {
			$this->options[$k] = $v;
		}
	}

    public function parse($file) {
		//ser vars
		$dir = '-';
		$options = '';
		$isRemote = preg_match('/^http(s)?\:\/\//i', $file);
		//format options
		foreach($this->options as $k => $v) {
			$options .= " -$k" . ($v ? " $v" : "");
		}
		//is remote file?
		if($isRemote) {
			$data = file_get_contents($file);
			$file = tempnam(sys_get_temp_dir(), 'pdf');
			file_put_contents($file, $data);
		}
		//format command
        $cmd = "{$this->executable}{$options} '{$file}' {$dir}";
		//run command
        $process = $this->process($cmd);
        //delete file?
        if($isRemote) {
			unlink($file);
        }
		//error found?
        if(!$process->output) {
            throw new \Exception("PDF extraction failed - " . ($process->error ?: 'unknown error'));
        }
		//return
        return trim($process->output, " \t\n\r\0\x0B\x0C");
    }

	protected function process($cmd) {
		//create result
		$res = (object) [
			'code' => 0,
			'error' => '',
			'output' => '',
		];
		//create tmp files
		$outFile = tempnam(".", "cmd");
		$errFile = tempnam(".", "cmd");
		//create descriptor
		$descriptor = [
			0 => [ "pipe", "r" ],
			1 => [ "file", $outFile, "w" ],
			2 => [ "file", $errFile, "w" ],
		];
		//open process?
		if($proc = proc_open($cmd, $descriptor, $pipes)) {
			//close first pipe
			fclose($pipes[0]);
			//close process
			$exit = proc_close($proc);
			//update result
			$res->code = $exit;
			$res->output = file_get_contents($outFile);
			$res->error = file_get_contents($errFile);
		}
		//remove tmp files
		unlink($outFile);
		unlink($errFile);
		//return
		return $res;
	}

}