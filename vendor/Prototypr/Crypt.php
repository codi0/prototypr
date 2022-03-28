<?php

namespace Prototypr;

class Crypt {

	protected $pepperKey = '';

	protected $defaults = array(
		'encoding' => 'base64',
		'hash' => 'sha256',
		'cipher' => 'aes-256-ctr',
		'curve' => 'prime256v1',
		'bits' => 2048,
		'token' => '::',
	);

	public function __construct(array $opts=[], $merge=true) {
		//set opts
		foreach($opts as $k => $v) {
			//property exists?
			if(property_exists($this, $k)) {
				//is array?
				if($merge && $this->$k === (array) $this->$k) {
					$this->$k = array_merge($this->$k, $v);
				} else {
					$this->$k = $v;
				}
			}
		}
		//openssl enabled?
		if(!extension_loaded('openssl')) {
			throw new \Exception("OpenSSL not enabled");
		}
	}

	public function keys($type, array $opts=array()) {
		//set opts
		$opts = array_merge($this->defaults, $opts);
		//create const
		$const = 'OPENSSL_KEYTYPE_' . strtoupper($type);
		//key options
		$keyOpts = array(
			'digest_alg' => $opts['hash'],
			'curve_name' => $opts['curve'],
			'private_key_bits' => (int) $opts['bits'],
			'private_key_type' => defined($const) ? constant($const) : OPENSSL_KEYTYPE_RSA,
		);
		//generation failed?
		if(($keygen = openssl_pkey_new($keyOpts)) === false) {
			return $this->failed($type . ' key generation failed');
		}
		//extract private key?
		if(openssl_pkey_export($keygen, $privKey) === false) {
			return $this->failed($type . ' private key export failed');
		}
		//extract public key?
		if(($pubKey = openssl_pkey_get_details($keygen)) === false) {
			return $this->failed($type . ' public key export failed');
		}
		//return
		return array( 'private' => $privKey, 'public' => $pubKey['key'] );
	}

	public function encode($input, $format) {
		//loop through formats
		foreach((array) $format as $f) {
			//base64 encode?
			if($f === 'base64') {
				//valid input?
				if(!is_string($input) && !is_numeric($input)) {
					return $this->failed('Only strings can be base64 encoded');
				}
				//encode input
				$input = base64_encode($input);
			}
			//hex encode?
			if($f === 'hex') {
				//valid input?
				if(!is_string($input) && !is_numeric($input)) {
					return $this->failed('Only strings can be hex encoded');
				}
				//encode input
				$input = bin2hex($input);
			}
			//json encode?
			if($f === 'json') {
				//encode success?
				if(($input = json_encode($input)) === false) {
					return $this->failed('Json encoding failed');
				}
			}
		}
		//return
		return $input;
	}

	public function decode($input, $format, $silent=false) {
		//can decode?
		if(!is_string($input)) {
			return $input;
		}
		//loop through formats
		foreach((array) $format as $f) {
			//base64 decode?
			if($input && $f === 'base64') {
				//decode success?
				if(($input = base64_decode($input, true)) === false) {
					return $this->failed('Base64 decoding failed', $silent);
				}
			}
			//hex decode?
			if($input && $f === 'hex') {
				if(($input = hex2bin($input)) === false) {
					return $this->failed('Hex decoding failed', $silent);
				}
			}
			//json decode?
			if($input && $f === 'json') {
				if(($input = json_decode($input, true)) === null) {
					return $this->failed('Json decoding failed', $silent);
				}
			}
		}
		//return
		return $input;
	}

	public function nonce($length=16, $raw=false) {
		//generate random bytes
		if(function_exists('random_bytes')) {
			$nonce = random_bytes($length);
		} else {
			$nonce = openssl_random_pseudo_bytes($length);
		}
		//generation failed?
		if($nonce === false) {
			return $this->failed('Random bytes generation failed');
		}
		//encode nonce?
		if(!$raw && $nonce = base64_encode($nonce)) {
			$nonce = preg_replace('/[^a-z0-9]/i', '', $nonce);
			$nonce = substr($nonce, 0, $length);
		}
		//return
		return $nonce;
	}

	public function number($length=16) {
		//generate nonce
		$nonce = $this->nonce($length*20);
		//numbers only
		$nonce = preg_replace('/[^0-9]/i', '', $nonce);
		$nonce = ltrim($nonce, '0');
		$nonce = substr($nonce, 0, $length);
		//return
		return $nonce;
	}

	public function uuid($data = null) {
		//generate nonce
		$nonce = $this->nonce(16, true);
		//set version to 0100
		$nonce[6] = chr(ord($nonce[6]) & 0x0f | 0x40);
		//set bits 6-7 to 10
		$nonce[8] = chr(ord($nonce[8]) & 0x3f | 0x80);
		//output as uuid
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($nonce), 4));
	}

	public function digest($input, $hash='sha256', $raw=false) {
		//valid digest?
		if(($digest = openssl_digest($input, $hash, $raw)) === false) {
			return $this->failed($hash . ' digest failed');
		}
		//return
		return $digest;
	}

	public function sign($input, $key, array $opts=array()) {
		//set opts
		$opts = array_merge($this->defaults, $opts);
		//generate signature?
		if(($signature = hash_hmac($opts['hash'], $input, $key, true)) === false) {
			return $this->failed($opts['hash'] . ' hash hmac failed');
		}
		//encode signature
		return $this->encode($signature, $opts['encoding']);
	}

	public function verify($input, $signature, $key, array $opts=array()) {
		//set opts
		$opts = array_merge($this->defaults, $opts);
		//decode signature?
		if(($signature = $this->decode($signature, $opts['encoding'])) === false) {
			return $this->failed();
		}
		//signature matched?
		return $signature === $this->sign($input, $key, $opts);
	}

	public function encrypt($input, $key, array $opts=array()) {
		//set opts
		$opts = array_merge($this->defaults, $opts);
		//get cypher IV length?
		if(($len = openssl_cipher_iv_length($opts['cipher'])) === false) {
			return $this->failed('Invalid IV cipher');
		}
		//generate IV
		if(($iv = $this->nonce($len, true)) === false) {
			return $this->failed();
		}
		//hash key?
		if(($key = $this->digest($key, $opts['hash'], true)) === false) {
			return $this->failed();
		}
		//encrypt using shared key?
		if(($encrypted = openssl_encrypt($input, $opts['cipher'], $key, OPENSSL_RAW_DATA, $iv)) === false) {
			return $this->failed('Encryption with symmetric key failed');
		}
		//encode result
		return $this->encode($iv . $encrypted, $opts['encoding']);
	}

	public function decrypt($input, $key, array $opts=array()) {
		//set opts
		$iv = null;
		$opts = array_merge($this->defaults, $opts);
		//decode input?
		if(($input = $this->decode($input, $opts['encoding'])) === false) {
			return $this->failed();
		}
		//get cypher IV length?
		if(($len = openssl_cipher_iv_length($opts['cipher'])) === false) {
			return $this->failed('Invalid IV cipher');
		}
		//parse IV?
		if(strlen($input) > $len) {
			$iv = substr($input, 0, $len);
			$input = substr($input, $len);
		}
		//hash key?
		if(($key = $this->digest($key, $opts['hash'], true)) === false) {
			return $this->failed();
		}
		//decrypt using shared secret?
		if(($decrypted = openssl_decrypt($input, $opts['cipher'], $key, OPENSSL_RAW_DATA, $iv)) === false) {
			return $this->failed('Decryption with symmetric key failed');
		}
		//return
		return $decrypted;
	}

	public function signRsa($input, $privKey, array $opts=array()) {
		//set opts
		$opts = array_merge($this->defaults, $opts);
		//generate signature?
		if(openssl_sign($input, $signature, $privKey, $opts['hash']) === false) {
			return $this->failed('Signing with private key failed');
		}
		//encode signature
		return $this->encode($signature, $opts['encoding']);
	}

	public function verifyRsa($input, $signature, $pubKey, array $opts=array()) {
		//set opts
		$opts = array_merge($this->defaults, $opts);
		//decode signature?
		if(($signature = $this->decode($signature, $opts['encoding'])) === false) {
			return $this->failed();
		}
		//verify signature
		if(($verify = openssl_verify($input, $signature, $pubKey, $opts['hash'])) == -1) {
			return $this->failed('Verifying with public key failed');
		}
		//return
		return $verify == 1;
	}

	public function encryptRsa($input, $pubKey, array $opts=array()) {
		//set opts
		$opts = array_merge($this->defaults, $opts);
		$tmpOpts = array_merge($opts, array( 'encoding' => 'raw' ));
		//token found?
		if(!$opts['token']) {
			return $this->failed('Encryption token not found');
		}
		//generate shared key?
		if(($sharedKey = $this->nonce(32)) === false) {
			return $this->failed();
		}
		//encrypt input with shared key?
		if(($encrypted = $this->encrypt($input, $sharedKey, $tmpOpts)) === false) {
			return $this->failed();
		}
		//encrypt shared key using public key?
		if(openssl_public_encrypt($sharedKey, $encryptedKey, $pubKey, OPENSSL_PKCS1_OAEP_PADDING) === false) {
			return $this->failed('Encrypting with public key failed');
		}
		//encode result
		return $this->encode($encryptedKey . $opts['token'] . $encrypted, $opts['encoding']);
	}

	public function decryptRsa($input, $privKey, array $opts=array()) {
		//set opts
		$opts = array_merge($this->defaults, $opts);
		$tmpOpts = array_merge($opts, array( 'encoding' => 'raw' ));
		//decode input?
		if(($input = $this->decode($input, $opts['encoding'])) === false) {
			return $this->failed();
		}
		//token found?
		if(!$opts['token'] || strpos($input, $opts['token']) === false) {
			return $this->failed('Encryption token not found');
		}
		//parse input
		list($encryptedKey, $input) = explode($opts['token'], $input, 2);
		//decrypt shared key using private key?
		if(openssl_private_decrypt($encryptedKey, $sharedKey, $privKey, OPENSSL_PKCS1_OAEP_PADDING) === false) {
			return $this->failed('Decryption using private key failed');
		}
		//decrypt input with shared key?
		if(($decrypted = $this->decrypt($input, $sharedKey, $tmpOpts)) === false) {
			return $this->failed('Decryption using symmetric key failed');
		}
		//return
		return $decrypted;
	}

	public function hashPwd($password, array $opts=[]) {
		//check password info
		$info = password_get_info($password);
		//anything to process?
		if(!$password || ($info && $info['algo'])) {
			return $password ?: null;
		}
		//default opts
		$opts = array_merge([
			'algo' => PASSWORD_DEFAULT,
		], $opts);
		//set algo
		$algo = $opts['algo'];
		unset($opts['algo']);
		//valid hash?
		if(!$hash = password_hash($password . $this->pepperKey, $algo, $opts)) {
			return $this->failed('Password hashing failed');
		}
		//return
		return $hash;
	}

	public function verifyPwd($password, $hash) {
		return password_verify($password . $this->pepperKey, $hash);
	}

	protected function failed($msg=null, $silent=false) {
		//throw exception?
		if($msg && !$silent) {
			throw new \Exception('Crypt error: ' . $msg);
		}
		//return
		return false;
	}

}