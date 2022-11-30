<?php

namespace App\Api\V1;

class Report extends \Prototypr\Route {

	public $path = 'v1/report';
	public $methods = [ 'POST', 'PUT' ];
	public $auth = true;
	public $hide = false;

	protected $inputFields = [
		'title' => [
			'source' => 'POST',
			'required' => true,
			'default' => null,
			'rules' => [],
			'filters' => [],
		],
		'description' => [
			'source' => 'POST',
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