<?php

namespace App;

class Api extends \Prototypr\Api {

	protected $basePath = '/api/';
	
	protected $routes = [
		'App\Api\V1\Check',
		'App\Api\V1\Report',
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

	protected function formatResponse(array $response) {
		//call parent
		$response = parent::formatResponse($response);
		//TO-DO: any additional formatting here
		return $response;
	}

	protected function auditLog(array $response, array $auditData=[]) {
		//TO-DO: handle api audit logging here
	}

}