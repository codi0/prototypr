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
	$object = $this->input('GET.object') ?: 'form';
	$url = $this->url('api/v1/todos');
	$method = $this->input('GET.method') ?: 'POST';
	$id = $this->input('GET.id') ?: 0;
	//generate UI
	if($object === 'table') {
		$output = $this->apiUi->$object($url);
	} else {
		$output = $this->apiUi->$object($url, $method, [ 'id' => $id ]);
	}
	//template
	$this->tpl('ui', [
		'output' => $output,
	]);
});


//Init: api service
//Api routes defined in /vendor/App/Api.php module file
$this->api->init();