<?php

/**
 *
 * Example module for creating an API server
 *
**/


//Config: api
$apiBase = '/api/';

//Helper: authenticated
$this->helper('apiAuth', function() {
	//set vars
	$isAuth = false;
	$token = $this->input('header.authorization');
	//TO-DO: query for matching token
	$tokenMatch = '12345';
	//valid token?
	if($token === $tokenMatch) {
		$isAuth = true;
	}
	//auth failed?
	if($isAuth === false) {
		//set json
		$this->json([
			'code' => 401,
		]);	
	}
	//return
	return $isAuth;
});

//404 response
$this->route([
	'path' => $apiBase . '404',
	'methods' => null,
	'auth' => null,
	'callback' => function($params) {
		//set json
		$this->json([
			'code' => 404,
		]);
	},
]);

//Endpoint: home
$this->route([
	'path' => $apiBase,
	'methods' => [ 'GET' ],
	'auth' => null,
	'callback' => function($params) use($apiBase) {
		//set vars
		$endpoints = [];
		$prefix = ltrim($apiBase, '/');
		//loop through routes
		foreach(array_keys($this->routes) as $name) {
			if(strpos($name, $prefix) === 0 && strpos($name, '404') === false) {
				$endpoints[] = str_replace($prefix, '', $name);
			}
		}
		//set json
		$this->json([
			'code' => 200,
			'data' => [
				'endpoints' => $endpoints,
			],
		]);
	},
]);

//Endpoint: check
$this->route([
	'path' => $apiBase . 'check',
	'methods' => [ 'GET' ],
	'auth' => [ $this, 'apiAuth' ],
	'callback' => function($params) {
		//TO-DO: api logic and data assembly
		$data = [
			'record_id' => 1,
		];
		//set json
		$this->json([
			'code' => 200,
			'data' => $data,
		]);
	},
]);

//Endpoint: report
$this->route([
	'path' => $apiBase . 'report',
	'methods' => [ 'POST', 'PUT' ],
	'auth' => [ $this, 'apiAuth' ],
	'callback' => function($params) {
		//TO-DO: api logic and data assembly
		$data = [
			'record_id' => 1,
		];
		//set json
		$this->json([
			'code' => 200,
			'data' => $data,
		]);
	},
]);