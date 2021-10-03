<?php

//Init: Load library
require_once('vendor/Codi0/Prototypr.php');

//Init: Create app
$app = \Codi0\Prototypr::singleton([
	'config' => [],
]);

//Route: Home
$app->route('/', function($app) {
	//load template
	$app->template('master:home', [
		'welcome' => 'Hi there!',
		'meta' => [
			'title' => 'Prototypr demo',
		],
	]);
});