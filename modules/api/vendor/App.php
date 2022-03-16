<?php

class App {

	protected static $instance;

	public static function setInstance($instance) {
		self::$instance = $instance;
	}

    public static function __callStatic($method, $args) {
		return self::$instance->$method(...$args);
    }

}