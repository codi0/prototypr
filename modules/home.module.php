<?php

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