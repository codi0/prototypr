<?php

namespace App\Api\V1;

class Report extends \Prototypr\Route {

	public $path = 'v1/report';
	public $methods = [ 'POST', 'PUT' ];
	public $auth = true;
	public $hide = false;

	protected $inputSchema = [
		'title' => [
			'desc' => 'The title of the report',
			'source' => 'POST',
			'type' => 'string',
			'required' => true,
			'default' => null,
			'rules' => [],
			'filters' => [],
		],
		'url' => [
			'desc' => 'The url to report',
			'source' => 'POST',
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