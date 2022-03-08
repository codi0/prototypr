<?php

//set vars
$isWp = (strpos(__FILE__, '/wp-content/') !== false);
$isWpLoaded = $isWp && isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'];

//is WordPress?
if(!$isWp) {
	return;
}

//use $wpdb?
if($isWpLoaded) {
	$this->service('db', $GLOBALS['wpdb']);
}

//scrape wp-config.php?
if(!$isWpLoaded) {
	//set vars
	$wpcPath = explode('/wp-content/', __FILE__)[0] . '/wp-config.php';
	//file exists?
	if(is_file($wpcPath)) {
		//loop through lines
		foreach(file($wpcPath) as $line) {
			//match found?
			if(preg_match('/DB_HOST|DB_USER|DB_PASS|DB_NAME/', $line, $m)) {
				//format key
				$key = strtolower($m[0]);
				$key = lcfirst(str_replace('_', '', ucwords($key, '_')));
				//format value
				$val = trim(explode(',', $line)[1]);
				$val = trim(explode(')', $val)[0]);
				$val = trim(explode('.', $val)[0]);
				$val = trim(trim($val, '"'), "'");
				//update config?
				if($key && $val) {
					$this->config($key, $val);
				}
			}
		}
	}
}