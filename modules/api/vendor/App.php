<?php

class App {

	private static $instance;

	public final static function setInstance($instance) {
		self::$instance = $instance;
	}

    public final static function __callStatic($method, $args) {
		return self::$instance->$method(...$args);
    }

}