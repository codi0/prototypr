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
		//has crypt?
		if(!$this->crypt) {
			$this->crypt = new Crypt;
		}
	}

	/* ENCODING */

	public function encode(array $claims, string $signKey, string $alg, array $headers=[]): string {
		//set default headers
		$headers['alg'] = $alg;
		$headers['typ'] = isset($headers['typ']) ? $headers['typ'] : 'JWT';
		//build segments
		$segments = array(
			0 => $this->b64urlEncode(json_encode($headers)),
			1 => $this->b64urlEncode(json_encode($claims)),
			2 => '',
		);
		//generate signature?
		if($headers['alg'] !== 'none') {
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

	public function decode(string $jwt, string|array $signKey, ?string $alg=null, ?string $aud=null): array {
		//get segments
		$segments = explode('.', $jwt);
		//has 3 segments?
		if(count($segments) !== 3) {
			return [];
		}
		//psrse segments
		$headers = json_decode($this->b64urlDecode($segments[0]), true);
		$claims = json_decode($this->b64urlDecode($segments[1]), true);
		$signature = isset($segments[2]) ? $segments[2] : '';
		//valid encoding?
		if(!is_array($headers) || !is_array($claims)) {
			return [];
		}
		//alg header present?
		if(!isset($headers['alg']) || !$headers['alg']) {
			return [];
		}
		//alg header matches?
		if($alg && $alg !== $headers['alg']) {
			return [];
		}
		//should signature be empty?
		if($headers['alg'] === 'none' && strlen($signature) !== 0) {
			return [];
		}
		//verify signature?
		if($headers['alg'] !== 'none') {
			//data to verify
			$data = $segments[0] . '.' . $segments[1];
			$sigMethod = (stripos($headers['alg'], 'H') === 0) ? 'verify' : 'verifyRsa';
			//format sign key?
			if(is_array($signKey)) {
				$n = isset($signKey['n']) ? $signKey['n'] : '';
				$e = isset($signKey['e']) ? $signKey['e'] : '';
				$signKey = $this->crypt->jwkToPemPublicKey($n, $e);
			}
			//valid sign key?
			if(!$signKey) {
				return [];
			}
			//run verification
			$isValid = $this->crypt->$sigMethod($data, $signature, $signKey, array(
				'hash' => 'sha' . preg_replace('/[^0-9]/', '', $headers['alg']),
				'format' => 'base64',
			));
			//is valid?
			if(!$isValid) {
				return [];
			}
		}
        //validate audience?
        if($aud && is_string($aud)) {
			//convert to array
			$claims['aud'] = isset($claims['aud']) ? $claims['aud'] : array();
			$claims['aud'] = is_array($claims['aud']) ? $claims['aud'] : array( $claims['aud'] );
			//audience found?
			if(!in_array($aud, $claims['aud'])) {
				return [];
			}
		}
		//validate expiration?
        if(isset($claims['exp'])) {
			//is numeric?
			if(!is_numeric($claims['exp'])) {
				return [];
			}
			//is valid time?
			if((time() - $this->leeway) >= (float) $claims['exp']) {
				return [];
			}
		}
		//validate issue time?
		if(isset($claims['iat'])) {
			//is numeric?
			if(!is_numeric($claims['iat'])) {
				return [];
			}
			//is valid time?
			if((time() + $this->leeway) < (float) $claims['iat']) {
				return [];
			}
		}
		//validate not before?
		if(isset($claims['nbf'])) {
			//is numeric?
			if(!is_numeric($claims['nbf'])) {
				return [];
			}
			//is valid time?
			if((time() + $this->leeway) < (float) $claims['nbf']) {
				return [];
			}
		}
		//return
		return [
			'headers' => $headers,
			'claims' => $claims,
			'signature' => $signature,
		];
	}

	/* ENCRYPTION */

	public function encrypt(string $plaintextJwt, array $recipientJwk): string
	{
		// Generate ephemeral EC key (P-256)
		$ephemeral = openssl_pkey_new([
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name' => 'prime256v1',
		]);

		if (!$ephemeral) {
			throw new \Exception("Failed to generate ephemeral EC key");
		}

		openssl_pkey_export($ephemeral, $ephemeralPrivPem);
		$ephemeralDetails = openssl_pkey_get_details($ephemeral);

		$epk = [
			'kty' => 'EC',
			'crv' => 'P-256',
			'x'   => $this->b64urlEncode($ephemeralDetails['ec']['x']),
			'y'   => $this->b64urlEncode($ephemeralDetails['ec']['y']),
		];

		$protectedHeader = [
			'alg' => 'ECDH-ES',
			'enc' => 'A256GCM',
			'epk' => $epk,
			'typ' => 'JWT',
		];

		// IMPORTANT: keep these separate
		$protectedJson = json_encode($protectedHeader, JSON_UNESCAPED_SLASHES);
		$protectedSeg  = $this->b64urlEncode($protectedJson); // AAD

		// Recipient public key (EC P-256)
		$recipientPem = $this->jwkToPemPublicKey($recipientJwk);

		// ECDH shared secret Z (OpenSSL 1.1.1 / 3.x compatible)
		$z = $this->ecdhDerive($ephemeralPrivPem, $recipientPem);

		// Concat KDF (RFC 7518 §4.6)
		$cek = $this->concatKdf($z, 'A256GCM', 256);

		$iv  = random_bytes(12);
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintextJwt,
			'aes-256-gcm',
			$cek,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$protectedSeg // AAD = protected header segment
		);

		if ($ciphertext === false) {
			throw new \Exception("AES-GCM encryption failed");
		}

		// JWE Compact Serialization (ECDH-ES direct => empty encrypted_key)
		return implode('.', [
			$protectedSeg,
			'',
			$this->b64urlEncode($iv),
			$this->b64urlEncode($ciphertext),
			$this->b64urlEncode($tag),
		]);
	}

	public function decrypt(string $jwe, string $recipientPrivateKeyPem): string
	{
		$parts = explode('.', $jwe);
		if (count($parts) !== 5) {
			throw new \Exception("Invalid JWE format");
		}

		[$protectedSeg, $encryptedKey, $ivB64, $ciphertextB64, $tagB64] = $parts;

		// ECDH-ES direct mode => encrypted_key MUST be empty
		if ($encryptedKey !== '') {
			throw new \Exception("Unexpected encrypted_key for ECDH-ES");
		}

		// Preserve exact AAD
		$protectedJson = $this->b64urlDecode($protectedSeg);
		$protected     = json_decode($protectedJson, true);

		if (!is_array($protected) || !isset($protected['alg'], $protected['enc'], $protected['epk'])) {
			throw new \Exception("Invalid JWE header");
		}

		$encMap = [
			'A128GCM' => 128,
			'A192GCM' => 192,
			'A256GCM' => 256,
		];

		if ($protected['alg'] !== 'ECDH-ES') {
			throw new \Exception("Unsupported JWE alg");
		}

		if (!isset($encMap[$protected['enc']])) {
			throw new \Exception("Unsupported JWE enc");
		}

		$encBits = $encMap[$protected['enc']];

		$epkPem = $this->jwkToPemPublicKey($protected['epk']);

		// ECDH shared secret Z (OpenSSL 1.1.1 / 3.x compatible)
		$z = $this->ecdhDerive($recipientPrivateKeyPem, $epkPem);

		$apu = isset($protected['apu']) ? $this->b64urlDecode($protected['apu']) : '';
		$apv = isset($protected['apv']) ? $this->b64urlDecode($protected['apv']) : '';

		$cek = $this->concatKdf($z, $protected['enc'], $encBits, $apu, $apv);

		$iv  = $this->b64urlDecode($ivB64);
		$tag = $this->b64urlDecode($tagB64);

		if (strlen($iv) !== 12) {
			throw new \Exception("Invalid IV length");
		}
		if (strlen($tag) !== 16) {
			throw new \Exception("Invalid GCM tag length");
		}

		$ciphertext = $this->b64urlDecode($ciphertextB64);

		$opensslCipher = match ($protected['enc']) {
			'A128GCM' => 'aes-128-gcm',
			'A192GCM' => 'aes-192-gcm',
			'A256GCM' => 'aes-256-gcm',
		};

		$plaintext = openssl_decrypt(
			$ciphertext,
			$opensslCipher,
			$cek,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$protectedSeg // AAD = exact first segment
		);

		if ($plaintext === false) {
			throw new \Exception("JWE decryption failed");
		}

		return $plaintext;
	}

	private function b64urlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	private function b64urlDecode(string $data): string {
		$data = strtr($data, '-_', '+/');
		return base64_decode($data . str_repeat('=', (4 - strlen($data) % 4) % 4));
	}

	private function concatKdf(string $z, string $algId, int $keyLenBits, string $apu = '', string $apv = '', string $suppPrivInfo = ''): string
	{
		$otherInfo =
			pack('N', strlen($algId)) . $algId .
			pack('N', strlen($apu))   . $apu .
			pack('N', strlen($apv))   . $apv .
			pack('N', $keyLenBits) .   // SuppPubInfo (keydatalen in bits, 32-bit big-endian)
			$suppPrivInfo;             // SuppPrivInfo (raw bytes, no length field)

		// For 128/192/256-bit keys with SHA-256 this single hash is sufficient (1 iteration).
		return hash('sha256', pack('N', 1) . $z . $otherInfo, true);
	}

	private function jwkToPemPublicKey(array $jwk): string {
		if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256') {
			throw new \Exception("Only EC P-256 JWK supported");
		}

		$x = $this->b64urlDecode($jwk['x']);
		$y = $this->b64urlDecode($jwk['y']);

		if (strlen($x) !== 32 || strlen($y) !== 32) {
			throw new \Exception("Invalid P-256 coordinate length");
		}

		$point = "\x04" . $x . $y;

		$spki =
			"\x30\x59" .
			"\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" .
			"\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07" .
			"\x03\x42\x00" . $point;

		return "-----BEGIN PUBLIC KEY-----\n"
			. chunk_split(base64_encode($spki), 64)
			. "-----END PUBLIC KEY-----\n";
	}

	private function ecdhDerive(string $privateKeyPem, string $publicKeyPem): string
	{
		// OpenSSL 3.x path
		if (function_exists('openssl_pkey_derive')) {
			$z = openssl_pkey_derive(
				openssl_pkey_get_public($publicKeyPem),
				openssl_pkey_get_private($privateKeyPem),
				32
			);
			if ($z === false) {
				throw new \Exception("ECDH derive failed (OpenSSL 3)");
			}
			return $z;
		}

		// OpenSSL 1.1.1 path
		$priv = openssl_pkey_get_private($privateKeyPem);
		$pub  = openssl_pkey_get_public($publicKeyPem);

		if (!$priv || !$pub) {
			throw new \Exception("Invalid EC keys for ECDH");
		}

		$privDetails = openssl_pkey_get_details($priv);
		$pubDetails  = openssl_pkey_get_details($pub);

		if (
			empty($privDetails['ec']['private_key']) ||
			empty($pubDetails['ec']['point'])
		) {
			throw new \Exception("EC key details unavailable");
		}

		// Compute shared secret Z
		$z = openssl_ecdh_compute_key(
			$pubDetails['ec']['point'],
			$priv
		);

		if ($z === false || strlen($z) !== 32) {
			throw new \Exception("ECDH compute failed (OpenSSL 1.1.1)");
		}

		return $z;
	}

}