<?php

//load lib
require_once('vendor/Codi0/Prototypr/App.php');

//init app
return prototypr([
	'config' => [
		//default global config
		'name' => 'Demo App',
		'env' => 'dev',
		'webcron' => true, //to use a server cronjob instead, set to false and then open cron.php
	],
	'config.prod' => [
		//env specific config (in this case, for "prod" env)
	],
]);