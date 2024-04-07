<?php

namespace Proto2\Security;

class Jwt {

	protected $leeway = 30;
	protected $crypt = null;

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
		//crypt present?
		if(!$this->crypt) {
			throw new \Exception("Crypt object not set");
		}
	}

	public function build(array $claims, $signKey, $isPublic) {
		//set algorithm
		$algo = $isPublic ? 'RS256' : 'HS256';
		//build JWT
		return $this->buildRaw(array(), $claims, $signKey, $algo);
	}

	public function parse($jwt, $signKey, $isPublic, $aud=null) {
		//set algorithm
		$algo = $isPublic ? 'RS256' : 'HS256';
		//parse JWT
		return $this->parseRaw($jwt, $signKey, $algo, $aud);
	}

	public function buildRaw(array $headers, array $claims, $signKey, $algo) {
		//valid input?
		if(!$algo || (!$signKey && $algo !== 'none') || ($signKey && $algo === 'none')) {
			throw new \Exception('Invalid $signKey and $algo combination');
		}
		//set default headers
		$headers['alg'] = $algo;
		$headers['typ'] = isset($headers['typ']) ? $headers['typ'] : 'JWT';
		//build segments
		$segments = array(
			0 => base64_encode(json_encode($headers)),
			1 => base64_encode(json_encode($claims)),
			2 => '',
		);
		//generate signature?
		if($algo !== 'none') {
			//data to sign
			$data = $segments[0] . '.' . $segments[1];
			$sigMethod = (stripos($headers['alg'], 'H') === 0) ? 'sign' : 'signRsa';
			//sign data
			$segments[2] = $this->crypt->$sigMethod($data, $signKey, array(
				'hash' => 'sha' . preg_replace('/[^0-9]/', '', $headers['alg']),
				'format' => 'base64',
			));
		}
        //return
        return implode('.', $segments);
	}

	public function parseRaw($jwt, $signKey, $algo, $aud=null) {
		//valid input?
		if(!$algo || (!$signKey && $algo !== 'none') || ($signKey && $algo === 'none')) {
			throw new \Exception('Invalid $signKey and $algo combination');
		}
		//get segments
		$segments = explode('.', $jwt);
		//has 3 segments?
		if(count($segments) !== 3) {
			return false;
		}
		//psrse segments
		$headers = json_decode(base64_decode($segments[0]), true);
		$claims = json_decode(base64_decode($segments[1]), true);
		$signature = isset($segments[2]) ? $segments[2] : '';
		//valid encoding?
		if(!is_array($headers) || !is_array($claims)) {
			return false;
		}
		//alg header matched?
		if(!isset($headers['alg']) || $headers['alg'] !== $algo) {
			return false;
		}
		//should signature be empty?
		if($headers['alg'] === 'none' && strlen($signature) !== 0) {
			return false;
		}
		//verify signature?
		if($headers['alg'] !== 'none') {
			//data to verify
			$data = $segments[0] . '.' . $segments[1];
			$sigMethod = (stripos($headers['alg'], 'H') === 0) ? 'verify' : 'verifyRsa';
			//run verification
			$isValid = $this->crypt->$sigMethod($data, $signature, $signKey, array(
				'hash' => 'sha' . preg_replace('/[^0-9]/', '', $headers['alg']),
				'format' => 'base64',
			));
			//is valid?
			if(!$isValid) {
				return false;
			}
		}
        //validate audience?
        if($aud && is_string($aud)) {
			//convert to array
			$claims['aud'] = isset($claims['aud']) ? $claims['aud'] : array();
			$claims['aud'] = is_array($claims['aud']) ? $claims['aud'] : array( $claims['aud'] );
			//audience found?
			if(!in_array($aud, $claims['aud'])) {
				return false;
			}
		}
		//validate expiration?
        if(isset($claims['exp'])) {
			//is numeric?
			if(!is_numeric($claims['exp'])) {
				return false;
			}
			//is valid time?
			if((time() - $this->leeway) >= (float) $claims['exp']) {
				return false;
			}
		}
		//validate issue time?
		if(isset($claims['iat'])) {
			//is numeric?
			if(!is_numeric($claims['iat'])) {
				return false;
			}
			//is valid time?
			if((time() + $this->leeway) < (float) $claims['iat']) {
				return false;
			}
		}
		//validate not before?
		if(isset($claims['nbf'])) {
			//is numeric?
			if(!is_numeric($claims['nbf'])) {
				return false;
			}
			//is valid time?
			if((time() + $this->leeway) < (float) $claims['nbf']) {
				return false;
			}
		}
		//return
		return array(
			'headers' => $headers,
			'claims' => $claims,
			'signature' => $signature,
		);
	}

}