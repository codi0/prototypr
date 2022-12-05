<?php

namespace App\Api\V1;

class Check extends \Prototypr\Route {

	public $path = 'v1/check';
	public $methods = [ 'GET' ];
	public $auth = false;
	public $hide = false;

	protected $inputSchema = [
		'title' => [
			'desc' => 'The title to check',
			'source' => 'GET',
			'type' => 'string',
			'required' => true,
			'default' => null,
			'rules' => [],
			'filters' => [],
		],
		'url' => [
			'desc' => 'The url to check',
			'source' => 'GET',
			'type' => 'string',
			'required' => false,
			'default' => null,
			'rules' => [ 'url' ],
			'filters' => [],
		],
	];

	protected $outputSchema = [];

	protected function doRoute(array $input, array $output) {
		//TO-DO: define api endpoint logic here
		$output['data']['record_id'] = 1;
		//return
		return $output;
	}

}