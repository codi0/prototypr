<?php

/**
 *
 * Example module for creating an API server
 *
**/


//Config: app facade
//Simplifies static calls to kernel - E.g. App::url()
$this->facade('App', $this);


//Route: Generate UI from API endpoint
$this->route('ui', function() {
	//set vars
	$url = $this->url('api/v1/todos');
	$method = $this->input('GET.method') ?: 'POST';
	//generate form
	echo \Prototypr\Form::fromApi($url, $method);
});


//Init: api service
//Api routes defined in /vendor/App/Api.php module file
$this->api->init();