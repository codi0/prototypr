<?php

namespace App;

class Api extends \Prototypr\Api {

	protected $basePath = '/api/';

	protected $routes = [
		'v1Home' => [
			'path' => 'v1',
			'auth' => false,
			'methods' => [],
		],
		'v1Report' => [
			'path' => 'v1/report',
			'auth' => true,
			'methods' => [ 'POST', 'PUT' ],
		],
		'v1Check' => [
			'path' => 'v1/check',
			'auth' => true,
			'methods' => [ 'GET' ],
		],
	];

	public function getKeyHeader() {
		//TO-DO: define scheme for retrieving api key here
		$apiKey = $this->kernel->input('header.authorization');
		//is dev?
		if(!$apiKey && $this->kernel->isEnv('dev')) {
			$apiKey = $this->kernel->input('authorization');
		}
		//return
		return $apiKey;
	}

	public function auth() {
		//set vars
		$result = false;
		$apiKey = $this->getKeyHeader();
		//TO-DO: define api key authentication here
		if($apiKey === '12345') {
			$result = true;
		} else {
			$this->addError('authorization', 'API key not valid');
		}
		//return
		return $result;
	}

	public function v1Home() {
		$this->home('v1');
	}

	public function v1Report(array $params) {
		//TO-DO: define api endpoint logic here
		$data = [
			'record_id' => 1,
		];
		//set response
		$this->respond([
			'code' => 200,
			'data' => $data,
		]);
	}

	public function v1Check(array $params) {
		//TO-DO: define api endpoint logic here
		$data = [
			'record_id' => 1,
		];
		//set response
		$this->respond([
			'code' => 200,
			'data' => $data,
		]);
	}

	protected function formatResponse(array $response) {
		//TO-DO: format json response here
		return $response;
	}

	protected function auditLog(array $response, array $auditData=[]) {
		//TO-DO: handle api audit logging here
	}

}