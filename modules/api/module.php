<?php

/**
 *
 * Example module for creating an API server
 *
**/


//Config: app facade
//Simplifies static calls to kernel - E.g. App::url()
$this->facade('App', $this);


//Init: api service
//Api routes defined in /vendor/App/Api.php module file
$this->api->init();