<?php

//Route: Home
$app->route('/', function($app) {
	//load template
	$app->tpl('home', [
		'welcome' => 'Hi there!',
		'meta' => [
			'title' => 'Prototypr demo',
		],
	]);
});