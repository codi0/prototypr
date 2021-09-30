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
	$app->template('home', [
		'welcome' => 'Hi there!',
		'page' => [
			'title' => 'Prototypr demo',
		],
	]);
});