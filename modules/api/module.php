<?php

/**
 *
 * Example module for creating an API server
 *
**/


//Config: app facade
//Simplifies static calls to kernel - E.g. App::url()
$this->facade('App', $this);


//API: todos endpoint
$this->api->addEndpoint('App\Api\V1\Todos');


//API: Init
$this->api->init();