<?php

/**
 *
 * Example module for creating routes with custom templates
 *
**/


//example dom manipulation
$this->event('app.html', function($html) {
	//load DOM
	$this->dom->load($html);
	//add child
	$this->dom->select('#app')->insertChild('<p>This paragraph was inserted by the DOM service.</p>');
	//return html
	return $this->dom->save();
});


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