<?php

namespace Proto2\Http;

class Parser {

	public static function rawRequest($message) {
		//set vars
		$parsed = [
			'uri' => '',
			'method' => '',
			'protocol' => '',
			'headers' => [],
			'body' => null,
			'get' => [],
			'post' => [],
			'cookies' => [],
			'files' => [],
		];
		//return
		return $parsed;
	}

}