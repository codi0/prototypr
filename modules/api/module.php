<?php

/**
 *
 * Example module for creating an API server
 *
**/


//Config: app facade
//Simplifies static calls to kernel - E.g. App::url()
\App::setInstance($this);

//Config: set api class
//Can also be added to a config file instead
$this->config('api_class', 'App\Api');


//Init: api service
//Api routes defined in /vendor/App/Api.php module file
$this->api->init();