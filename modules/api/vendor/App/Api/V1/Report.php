<?php

namespace App\Api\V1;

class Report extends \Prototypr\Route {

	public $path = 'v1/report';
	public $methods = [ 'POST', 'PUT' ];
	public $auth = true;
	public $public = true;

	protected $inputSchema = [
		'title' => [
			'desc' => 'The title of the report',
			'type' => 'string',
			'default' => null,
			'contexts' => [
				'POST' => [
					'required' => true,
					'source' => 'body',
				],
				'PUT' => [
					'required' => true,
					'source' => 'body',
				],
			],
			'rules' => [],
			'filters' => [],
		],
		'url' => [
			'desc' => 'The url to report',
			'type' => 'string.url',
			'default' => null,
			'contexts' => [
				'POST' => [
					'required' => false,
					'source' => 'body',
				],
				'PUT' => [
					'required' => false,
					'source' => 'body',
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