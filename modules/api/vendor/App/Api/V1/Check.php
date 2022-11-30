<?php

namespace App\Api\V1;

class Check extends \Prototypr\Route {

	public $path = 'v1/check';
	public $methods = [ 'GET' ];
	public $auth = false;
	public $hide = false;

	protected $inputFields = [
		'title' => [
			'source' => 'GET',
			'required' => true,
			'default' => null,
			'rules' => [],
			'filters' => [],
		],
		'description' => [
			'source' => 'GET',
			'required' => false,
			'default' => null,
			'rules' => [],
			'filters' => [],
		],
	];

	protected function doRoute(array $input, array $output) {
		//TO-DO: define api endpoint logic here
		$output['data']['record_id'] = 1;
		//return
		return $output;
	}

}