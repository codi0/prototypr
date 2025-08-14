<?php

/**
 * Plugin Name: Prototypr
 * Plugin URI: https://github.com/codi0/prototypr
 * Description: A micro php library to help develop apps quickly
 * Version: 2.0.0
**/


//set version?
if(!defined('PROTOTYPR_VERSION')) {
	define('PROTOTYPR_VERSION', 1);
}

//use v1?
if(PROTOTYPR_VERSION == 1) {

	//load kernel?
	if(!class_exists('Prototypr\Kernel', false)) {
		require_once(__DIR__ . '/vendor/Prototypr/Kernel.php');
	}

	//launch app
	return prototypr();
	
}

//use v2?
if(PROTOTYPR_VERSION == 2) {

	//load kernel?
	if(!class_exists('Proto2\App\Kernel', false)) {
		require_once(__DIR__ . '/vendor/Proto2/App/Kernel.php');
	}

	//launch app
	return Proto2();
	
}