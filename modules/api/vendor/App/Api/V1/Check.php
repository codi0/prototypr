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
			'type' => 'string',
			'default' => null,
			'contexts' => [
				'GET' => [
					'required' => true,
					'source' => 'url',
				],
			],
			'rules' => [],
			'filters' => [],
		],
		'url' => [
			'desc' => 'The url to check',
			'type' => 'string.url',
			'default' => null,
			'contexts' => [
				'GET' => [
					'required' => false,
					'source' => 'url',
				],
			],
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