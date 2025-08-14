<?php

/**
 *
 * Example v1 module for creating a ToDo List App
 *
**/


//stop here?
if(PROTOTYPR_VERSION != 1) {
	return;
}


//API: todos endpoint
$this->api->addEndpoint('App\Api\V1\Todos');

//API: Init
$this->api->init();

//Route: Generate UI from todos API
$this->route('todos', function() {
	//get TODOs endpoint
	$endpoint = $this->url('api/v1/todos');
	//auto generate CRUD ui screens (list, add, edit, delete) using the ApiUi class
	//for more control, use the form and table methods directly (see the crud method internals for a usage example)
	$output = $this->apiUi->crud($endpoint, [
		'title' => 'Todo list',
		'schema_url' => $endpoint . '/schema', //optionally set the schema URL, if different from the main endpoint
	]);
	//add custom css
	$output->before('<style>.form-wrap .title { margin: 15px 0; }</style>');
	//template
	$this->tpl('output', [
		'output' => $output,
	]);
});