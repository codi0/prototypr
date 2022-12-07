<?php

namespace App\Api\V1;

class Check extends \Prototypr\Route {

	public $path = 'v1/check';
	public $methods = [ 'GET' ];
	public $auth = false;
	public $public = true;

	protected $inputSchema = [
		'title' => [
			'desc' => 'The title to check',
			'contexts' => [
				'GET' => 'required',
			],
			'source' => 'GET',
			'type' => 'string',
			'default' => null,
			'rules' => [],
			'filters' => [],
		],
		'url' => [
			'desc' => 'The url to check',
			'contexts' => [
				'GET' => 'optional',
			],
			'source' => 'GET',
			'type' => 'string.url',
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