<?php

/**
 * Plugin Name: Prototypr
 * Plugin URI: https://github.com/codi-si/prototypr
 * Description: A micro php library to help develop apps quickly
 * Version: 1.0.1
 *
 *
 * ==============
 * CONFIG OPTIONS
 * ==============
 *
 * Config data can be passed directly below, or by creating file(s) containing a php array in the "/data/config/" folder.
 *
 * Open the "/vendor/Prototypr/Kernel.php" file for a full list of config and other options that can be set.
 *
**/


//load kernel
require_once(__DIR__ . '/vendor/Prototypr/Kernel.php');

//launch app
return prototypr([

	'config' => [
	
		//App name
		'name' => 'Demo App',

		//Set app version number, triggering app.upgrade event when version updated
		'version' => NULL,

		//If not set, $_SERVER vars will be used to construct the base url
		'base_url' => '',

		//If not set, HTTP_HOST will be scanned for potential matches (E.g. ^dev.)
		//Supported envs are dev, qa, staging, prod
		'env' => 'dev',
		
		//Designates a module to function as a theme (set to NULL to disable theme usage)
		'theme' => 'theme',

		//To use a server cronjob instead, set this to FALSE and then call this file from your cronjob
		//E.g. "/path/to/index.php -cron -baseUrl={url here}" 
		'webcron' => TRUE,

		//When to call the app "run" method
		//Options are constructor, destructor and NULL (if NULL, method must be called manually)
		'autorun' => 'constructor',
		
		//Select which modules are loaded (if empty, all modules will be loaded automatically)
		'modules' => [],
		
		//Whether to log php errors to "/data/logs/" directory (if FALSE, default php error log location will be used)
		'custom_error_log' => TRUE,

		//mail from details
		'mail_from' => '',
		'mail_name' => '',

		//Database login
		'db_opts' => [
			'host' => 'localhost',
			'name' => NULL,
			'user' => NULL,
			'pass' => NULL,
		],

	],

	'config.dev' => [
		//overriding config for "dev" environment
	],

	'config.qa' => [
		//overriding config for "qa" environment
	],

	'config.staging' => [
		//overriding config for "staging" environment
	],

	'config.prod' => [
		//overriding config for "prod" environment
	],

]);