<?php

namespace Proto2\Http;

//PSR-7 compatible
class UploadedFile {

    protected $clientFilename;
    protected $clientMediaType;
    protected $error;
    protected $file;
    protected $moved = false;
    protected $size;
    protected $stream;

    protected $errorCodes = [
        UPLOAD_ERR_OK => 1,
        UPLOAD_ERR_INI_SIZE => 1,
        UPLOAD_ERR_FORM_SIZE => 1,
        UPLOAD_ERR_PARTIAL => 1,
        UPLOAD_ERR_NO_FILE => 1,
        UPLOAD_ERR_NO_TMP_DIR => 1,
        UPLOAD_ERR_CANT_WRITE => 1,
        UPLOAD_ERR_EXTENSION => 1,
    ];

	public static function createFromGlobals() {
		return self::formatFiles($_FILES);
	}

	public static function formatFiles(array $files=[]) {
		$res = [];
		foreach($files as $key => $value) {
			if($value instanceof self) {
				$res[$key] = $value;
			} elseif(is_array($value) && isset($value['tmp_name'])) {
				$res[$key] = new self($value['tmp_name'], $value['size'], $value['error'], $value['name'], $value['type']);
			} elseif(is_array($value)) {
				$res[$key] = self::formatFiles($value);
			} else {
				throw new \InvalidArgumentException('Invalid file input');
			}
		}
		return $res;
	}

	public function __construct($streamOrFile, $size, $errorStatus, $clientFilename=null, $clientMediaType=null) {
		if(!is_int($errorStatus) || !isset($this->errorCodes[$errorStatus])) {
			throw new \InvalidArgumentException('Upload file error status must be an integer value and one of the "UPLOAD_ERR_*" constants.');
		}
		if(!is_int($size)) {
			throw new \InvalidArgumentException('Upload file size must be an integer');
		}
		$this->error = $errorStatus;
		$this->size = $size;
		$this->clientFilename = $clientFilename;
		$this->clientMediaType = $clientMediaType;
        if($this->error === UPLOAD_ERR_OK) {
			if(is_string($streamOrFile)) {
				$this->file = $streamOrFile;
			} elseif(is_resource($streamOrFile)) {
				$this->stream = new Stream($streamOrFile);
			} elseif($streamOrFile instanceof Stream) {
				$this->stream = $streamOrFile;
			} else {
				throw new \InvalidArgumentException('Invalid stream or file provided for UploadedFile');
			}
		}
	}

	public function getStream() {
		$this->validateActive();
		return $this->stream ?: new Stream(fopen($this->file, 'r'));
	}

	public function getSize() {
		return $this->size;
	}

	public function getError() {
		return $this->error;
	}

	public function getClientFilename() {
		return $this->clientFilename;
	}

	public function getClientMediaType() {
		return $this->clientMediaType;
	}

	public function moveTo($targetPath) {
		if(!$targetPath || !is_dir(dirname($targetPath))) {
			throw new \RuntimeException('Invalid target path');
		}
		$this->validateActive();
        if($this->file) {
            $this->moved = (PHP_SAPI === 'cli') ? rename($this->file, $targetPath) : move_uploaded_file($this->file, $targetPath);
		} else {
			$stream = $this->getStream();
			if($stream->isSeekable()) {
				$stream->rewind();
			}
			$dest = new Stream(fopen($targetPath, 'w'));
			while(!$stream->eof()) {
				if(!$dest->write($stream->read(1048576))) {
					break;
				}
			}
			$this->moved = true;
		}
		if(!$this->moved) {
			throw new \RuntimeException('Uploaded file could not be moved to ' . $targetPath);
		}
	}

	protected function validateActive() {
		if($this->error !== UPLOAD_ERR_OK) {
			throw new \RuntimeException('Cannot retrieve stream due to upload error');
		}
		if($this->moved) {
			throw new \RuntimeException('Cannot retrieve stream after it has already been moved');
		}
	}

}