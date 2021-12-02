<?php

/* THIS IS AN EXAMPLE FILE TO CALL USING A SERVER CRONJOB */

//config vars
$ssl = 'on';
$script = '/cron.php';
$host = 'yourdomain.com';

//run cron?
if(isset($_SERVER['argv'])) {
	//env vars
	$_SERVER['HTTPS'] = $ssl;
	$_SERVER['HTTP_HOST'] = $host;
	$_SERVER['SCRIPT_NAME'] = $script;
	//load app
	$app = require_once('index.php');
	//run cron
	$app->cron();
}