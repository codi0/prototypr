<?php

/**
 *
 * Example module for creating routes with custom templates
 *
**/


//Route: Home
$this->route('/', function() {
	//load template
	$this->tpl('home', [
		'welcome' => 'Hi there!',
		'meta' => [
			'title' => 'Prototypr demo',
			'noindex' => true,
		],
		'js' => [
			'userId' => 1, //dummy data
			'evil' => "alert('haha!')", //to show evil content is auto-escaped
		],
	]);
});