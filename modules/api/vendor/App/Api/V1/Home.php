<?php

namespace App\Api\V1;

class Home extends \Prototypr\Route {

	public $path = 'v1';
	public $methods = [];
	public $auth = false;
	public $hide = true;

	protected $inputFields = [];

	public function doCallback() {
		return $this->kernel->api->home('v1');
	}

}