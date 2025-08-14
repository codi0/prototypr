<?php

/**
 *
 * Example v1 module for creating routes
 *
**/


//stop here?
if(PROTOTYPR_VERSION != 1) {
	return;
}


//Config: app facade
//Simplifies static calls to kernel - E.g. App::url()
$this->facade('App', $this);

//Config: set theme?
if(!$this->config('theme')) {
	$this->config('theme', 'theme');
}

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

//Event: Example DOM manipulation
$this->event('app.html', function($html) {
	//get route
	$route = $this->config('route');
	//is home page?
	if($route && !$route->path) {
		//load DOM
		$this->dom->load($html);
		//add child
		$this->dom->select('#app')->insertChild('<p>This paragraph was inserted by the DOM service.</p>');
		//save html
		$html = $this->dom->save();
	}
	//return
	return $html;
});