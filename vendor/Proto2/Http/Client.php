<?php

//PSR-18 compatible (without interfaces)

namespace Proto2\Http;

class Client {

	protected $transport = '';
	protected $cacert = '';
	protected $disabledFuncs = [];

	protected $eventManager;

	public function __construct(array $opts=[], $merge=true) {
		//set opts
		foreach($opts as $k => $v) {
			//property exists?
			if(property_exists($this, $k)) {
				//is array?
				if(!$merge || !is_array($this->$k) || !is_array($v)) {
					$this->$k = $v;
					continue;
				}
				//loop through array
				foreach($v as $a => $b) {
					$this->$k[$a] = $b;
				}
			}
		}
		//set disabled funcs?
		if(!$this->disabledFuncs) {
			$this->disabledFuncs = array_map('trim', explode(',', ini_get('disable_functions')));
		}
		//select transport?
		if(!$this->transport) {
			if(function_exists('stream_socket_client') && !in_array('stream_socket_client', $this->disabledFuncs)) {
				$this->transport = 'socket';
			} elseif(function_exists('fsockopen') && !in_array('fsockopen', $this->disabledFuncs)) {
				$this->transport = 'socket';
			} elseif(extension_loaded('curl') && function_exists('curl_init')) {
				$this->transport = 'curl';
			} elseif(ini_get('allow_url_fopen')) {
				$this->transport = 'file';
			} else {
				throw new \Exception('Please contact your web host to enable remote HTTP requests via php');
			}
		}
		//check ssl
		$this->checkSsl();
	}

	public function send($url, array $opts=[], $redirectNum=0) {
		//format opts
		$opts = array_merge([
			'method' => 'GET',
			'headers' => [],
			'body' => null,
			'params' => [],
			'protocol' => '1.0',
			'transport' => $this->transport,
			'timeout' => 3,
			'redirects' => 3,
			'sslverify' => true,
			'sslcert' => $this->cacert,
		], $opts);
		//format headers?
		if(is_array($opts['headers'])) {
			//tmp var
			$tmp = '';
			$isServer = isset($opts['headers']['REQUEST_METHOD']);
			//loop through array
			foreach($opts['headers'] as $name => $value) {
				//skip header?
				if($isServer && strpos($name, 'HTTP_') !== 0) {
					continue;
				}
				//format name
				$name = str_replace([ '-', '_' ], ' ', strtolower($name));
				$name = str_replace(' ', '-', ucwords(trim($name)));
				$name = preg_replace('/^Http-/', '', $name);
				//add to string?
				if($name && $value) {
					$value = is_array($value) ? implode(', ', $value) : $value;
					$tmp .= $name . ": " . trim($value) . "\r\n";
				}
			}
			//set as string
			$opts['headers'] = $tmp;
		}
		//format body?
		if(is_array($opts['body'])) {
			$opts['body'] = http_build_query($opts['body']);
		}
		//format params?
		if(is_array($opts['params'])) {
			$opts['params'] = http_build_query($opts['params']);
		}
		//add params?
		if($opts['params']) {
			$url .= (strpos($url, '?') > 0 ? '&' : '?') . $opts['params'];
		}
		//trim input
		$opts['method'] = strtoupper($opts['method']);
		$opts['headers'] = trim($opts['headers']);
		$opts['body'] = trim($opts['body']);
		//set vars
		$code = 0;
		$headers = [];
		$body = null;
		$protocol = null;
		$useMethod = 'use' . ucfirst(strtolower($opts['transport']));
		//buffer
		ob_start();
		//display errors
		$e1 = error_reporting();
		error_reporting(E_ALL);
		$e2 = ini_get('display_errors');
		ini_set('display_errors', 1);
		$e3 = set_error_handler(function() {});
		//make request
		$response = $this->$useMethod($url, $opts, $message);
		//get buffer output
		$buffer = ob_get_clean();
		//restore errors
		error_reporting($e1);
		ini_set('display_errors', $e2);
		set_error_handler($e3);
		//ssl error found?
		if($buffer && stripos($buffer, 'OpenSSL Error') !== false) {
			$message = 'SSL verification failed for ' . parse_url($url, PHP_URL_HOST);
		}
		//parse response?
		if(is_string($response)) {
			//parse response parts
			$parts = explode("\r\n\r\n", $response);
			//loop through parts
			foreach($parts as $part) {
				//is headers?
				if(strpos($part, 'HTTP/') === 0) {
					$headers = explode("\r\n", $part);
				} else {
					$body .= $part . "\r\n\r\n";
				}
			}
			//format headers
			foreach($headers as $key => $val) {
				//remove header
				unset($headers[$key]);
				//is status?
				if($key === 0) {
					//status header
					$exp = explode(' ', $val);
					$protocol = str_replace('HTTP/', '', $exp[0]);
					$code = (int) $exp[1];
					$message = $exp[2];
				} else {
					//other headers
					$exp = array_map('trim', explode(':', $val, 2));
					$key = implode('-', array_map('ucfirst', explode('-', strtolower(trim($exp[0])))));
					$headers[$key] = $exp[1];
				}
			}
			//redirect request?
			if(isset($headers['Location']) && $headers['Location'] && $redirectNum < $opts['redirects']) {
				//make absolute url?
				if($headers['Location'][0] === '/') {
					$parse = parse_url($url);
					$headers['Location'] = $parse['scheme'] . '://' . $parse['host'] . $headers['Location'];
				}
				//follow redirect
				return $this->send($headers['Location'], $opts, ++$redirectNum);
			}
			//trim body
			$body = trim($body);
			//json decode body?
			if($body && ($test = json_decode($body, true)) !== null) {
				$body = $test;
			}
			//can parse?
			if($body && is_string($body)) {
				//check first key
				$exp = explode('=', $body);
				//is valid key?
				if(isset($exp[1]) && strlen($exp[0]) < 30 && strpos($exp[0], ' ') === false) {
					//parse string
					parse_str($body, $test);
					//update params?
					if($test) $body = $test;
				}
			}
		}
		//build response
		$response = [
			'url' => rtrim($url, '/'),
			'code' => $code,
			'message' => $message,
			'protocol' => $protocol,
			'headers' => $headers,
			'body' => $body,
		];
		//dispatch event?
		if($this->eventManager) {
			//http client event
			$e = $this->eventManager->dispatch('http.client', $response);
			//merge response
			$response = array_merge($response, $e->getParams());
		}
		//return
		return $response;
	}

	public function sendRequest($request, array $opts=[]) {
		//get unwrapped result
		$res = $this->send($request->getUri(), array_merge([
			'method' => $request->getMethod(),
			'headers' => $request->getHeaders(),
			'body' => $request->getBody(),
		], $opts));
		//wrap in Response
		return new Response($res['code'], $res['headers'], $res['body'], $res['protocol']);
	}

	protected function useSocket($url, array $opts, &$error) {
		//set vars
		$response = '';
		$parts = parse_url($url);
		//format parts?
		if(is_array($parts)) {
			//add default parts
			$parts = array_merge([ 'scheme' => 'http', 'host' => '', 'port' => null, 'path' => '/', 'query' => '' ], $parts);
			//check scheme
			if($parts['scheme'] === 'https') {
				$port = $parts['port'] ?: 443;
				$scheme = 'ssl://';
			} else {
				$port = $parts['port'] ?: 80;
				$scheme = 'tcp://';	
			}
		}
		//valid url?
		if($parts && $parts['host']) {
			//select handle
			if(function_exists('stream_socket_client') && !in_array('stream_socket_client', $this->disabledFuncs)) {
				$context = $this->createContext($url, $opts);
				$host = $scheme . $parts['host'] . ':' . $port;
				$handle = stream_socket_client($host, $errNo, $errMsg, $opts['timeout'], STREAM_CLIENT_CONNECT, $context);
			} else {
				$host = $scheme . $parts['host'];
				$handle = fsockopen($host, $port, $errNo, $errMsg, $opts['timeout']);
			}
			//can open?
			if($handle) {
				//format request
				$request  = $opts['method'] . " " . $parts['path'] . ($parts['query'] ? '?' . $parts['query'] : '') . " HTTP/" . $opts['protocol'] . "\r\n";
				$request .= "Host: " . $parts['host'] . "\r\n";
				if($opts['body']) {
					$request .= "Content-Type: application/x-www-form-urlencoded" . "\r\n";
					$request .= "Content-Length: " . strlen($opts['body']) . "\r\n";
				}
				if($opts['headers']) {
					$request .= $opts['headers'] . "\r\n";
				}
				$request .= "Connection: Close" . "\r\n\r\n";
				$request .= $opts['body'];
				//send request
				fputs($handle, $request);   
				//set read timeout
				stream_set_timeout($handle, $opts['timeout']);		
				//read response
				while(!feof($handle)) {
					//get next line
					$line = fgets($handle, 4096);
					//get meta data
					$info = stream_get_meta_data($handle);
					//has timed out?
					if($line === false || $info['timed_out']) {
						break;
					}
					//add to response
					$response .= $line;
				}
				//close
				fclose($handle);
			}
		}
		//has error?
		if($errNo || strpos($response, "\r\n\r\n") === false) {
			$response = false;
			$error = $errMsg ?: 'Request failed';
		}
		//return
		return $response;
	}

	protected function useCurl($url, array $opts, &$error) {
		//add header
		$opts['headers'] = 'Expect:' . "\r\n" . $opts['headers'];
		//create handle
		$handle = curl_init();
		//set options
		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_HEADER, true);
		curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $opts['timeout']);
		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $opts['method']);
		curl_setopt($handle, CURLOPT_HTTPHEADER, explode("\r\n", $opts['headers']));
		curl_setopt($handle, CURLOPT_POSTFIELDS, $opts['body']);
		//use ssl?
		if($opts['sslverify']) {
			curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
			curl_setopt($handle, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
			//cert provided?
			if($opts['sslcert']) {
				curl_setopt($handle, CURLOPT_CAINFO, $opts['sslcert']);
			}
		}
		//make request
		$response = curl_exec($handle);
		//check for error
		$errNo = curl_errno($handle);
		$errMsg = curl_error($handle) ?: (function_exists('curl_strerror') ? curl_strerror($errNo) : 'CURL error');
		//close handle
		curl_close($handle);
		//has error?
		if($errNo || strpos($response, "\r\n\r\n") === false) {
			$response = false;
			$error = $errMsg ?: 'Request failed';
		}
		//return
		return $response;
	}

	protected function useFile($url, array $opts, &$error) {
		//set vars
		$headers = $errNo = $errMsg = '';
		//set content type?
		if($opts['body']) {
			$opts['headers']  = $opts['headers'] ? $opts['headers'] . "\r\n" : '';
			$opts['headers'] .= "Content-Type: application/x-www-form-urlencoded" . "\r\n";
			$opts['headers'] .= "Content-Length: " . strlen($opts['body']) . "\r\n";
		}
		//create context
		$context = $this->createContext($url, $opts);
		//make request
		$response = file_get_contents($url, false, $context);
		//loop through headers
		foreach($http_response_header as $header) {
			//reset headers?
			if(strpos($header, 'HTTP/') === 0) {
				$headers = '';
			}
			//add header
			$headers .= trim($header) . "\r\n";
		}
		//add headers to response
		$response = $headers . "\r\n" . $response;
		//has error?
		if($errNo || strpos($response, "\r\n\r\n") === false) {
			$response = false;
			$error = $errMsg ?: 'Request failed';
		}
		//return
		return $response;
	}

	protected function createContext($url, array $opts) {
		//context options
		$context = [
			'http' => [
				'method' => $opts['method'],
				'header' => $opts['headers'],
				'content' => $opts['body'],
				'timeout' => $opts['timeout'],
				'follow_location' => 0,
				'protocol_version' => $opts['protocol'],
			],
		];
		//use ssl?
		if($opts['sslverify']) {
			$context['ssl'] = [
				'verify_peer' => true,
				'peer_name' => parse_url($url, PHP_URL_HOST),
				'ciphers' => 'HIGH:!SSLv2:!SSLv3',
				'disable_compression' => true,
			];
			//set cert?
			if($opts['sslcert']) {
				$context['ssl']['cafile'] = $opts['sslcert'];
			}
		}
		//return context
		return stream_context_create($context);
	}

	protected function checkSsl() {
		//get SSL cert location
		$certLocations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
		//certificate already configured?
		if(!$certLocations || !isset($certLocations['default_cert_file']) || !$certLocations['default_cert_file']) {
			//set custom cert path
			$cacert = __DIR__ . '/certificates/cacert.pem';
			$this->cacert = is_file($cacert) ? $cacert : '';
			//cert exists?
			if(!$this->cacert) {
				//attempt download
				$res = $this->send('https://curl.haxx.se/ca/cacert.pem', [ 'sslverify' => false ]);
				//download success?
				if($res['code'] == 200 && $res['body']) {
					//create dir?
					if(!is_dir(dirname($cacert))) {
						mkdir(dirname($cacert), 0700);
					}
					//save file?
					if(file_put_contents($cacert, $res['body'], LOCK_EX) > 0) {
						chmod($cacert, 0600);
						$this->cacert = $cacert;
					}
				}
			}
		}
	}

}