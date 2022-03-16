<?php

/**
 *
 * Example module for creating an API server
 *
**/


//Config: app facade
//simplifies static calls to kernel
//E.g. App::url()
\App::setInstance($this);

//Config: set api class
//can also be added to a config file instead
$this->config('api_class', 'App\Api');


//Init: api service
//api routes defined in /vendor/App/Api.php module file
$this->api->init();