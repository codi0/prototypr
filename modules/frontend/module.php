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
	//template
	$this->tpl('ui', [
		'output' => $output,
	]);
});

//Event: Example DOM manipulation
$this->event('app.html', function($html) {
	//is home page?
	if(!$this->config('route')->path) {
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