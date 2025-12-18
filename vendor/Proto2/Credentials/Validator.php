<?php

namespace Proto2\Credentials;

/**
 * Validator for mdoc-based credentials obtained via the W3C Digital
 * Credentials API (navigator.credentials.get).
 *
 * This implementation verifies Mobile Document (mdoc) data structures
 * and cryptographic objects as defined in:
 *
 *   ISO/IEC 18013-5:2021
 *   "Personal identification — ISO-compliant driving licence —
 *    Part 5: Mobile driving licence (mDL) application"
 *
 * Specifically, this code validates:
 *   - Mobile Security Object (MSO)
 *   - valueDigests and issuer-signed data integrity
 *   - IssuerSigned and DeviceSigned structures
 *   - COSE_Sign1 signatures and COSE_Key public keys
 *   - Issuer and device authentication keys
 *
 * IMPORTANT:
 * This validator does NOT implement the ISO/IEC 18013-5 offline mdoc
 * retrieval protocol (e.g., DeviceEngagement, EReaderKey, or the ISO
 * SessionTranscript format).
 *
 * Instead, it implements the session binding and device authentication
 * model used by browser-based wallets via the W3C Digital Credentials
 * API, following the OpenID for Verifiable Presentations (OID4VP) /
 * DCAPI "mdoc handover" profile.
 *
 * In this web-based profile:
 *   - The SessionTranscript is replaced with the
 *     "OpenID4VPDCAPIHandover" construction
 *   - DeviceAuthentication excludes the ISO 18013-5 DocType field
 *   - Session binding is achieved via verifier-provided nonce and
 *     origin hashing, rather than ISO offline engagement
 *
 * References:
 *   - ISO/IEC 18013-5:2021 (mdoc data model and cryptography)
 *   - W3C Digital Credentials API (navigator.credentials.get)
 *   - OpenID for Verifiable Presentations (OID4VP)
 *
 * This hybrid approach reflects current interoperable browser and
 * wallet implementations and is intentionally aligned with deployed
 * Web mdoc verification behavior.
 *
 * Implementation requires PHP 8.1+
 */

class Validator
{

    private string $nonce;
    private string $origin;

    private ?object $encryptionKey;
	private ?object $jwt;

    private array $debug = [];

    /** @var string[] PEM root certs */
    private array $trustedRootCerts = [];

    public function __construct(string $nonce, string $origin, ?object $encryptionKey = null, ?object $jwt = null)
    {
        $this->nonce = $nonce;
        $this->origin = $origin;
        $this->encryptionKey = $encryptionKey;
        $this->jwt = $jwt;
        
        if(!$this->jwt) {
			$this->jwt = new \Proto2\Security\Jwt;
        }
    }

    /* ============================================================
     * Public API
     * ============================================================
     */

	public function getDebugLog(): array
	{
		return $this->debug;
	}

    public function loadTrustedRootsFromPemFile(string $pemPath): void
    {
        $pem = file_get_contents($pemPath);
        if ($pem === false) {
            throw new \RuntimeException("Failed to read PEM file");
        }

        preg_match_all(
            '/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s',
            $pem,
            $m
        );

        foreach ($m[0] as $cert) {
            $this->trustedRootCerts[] = $cert;
        }
    }

    public function validateCredentialResponse(string $credentialResponse): array
    {
		if($this->encryptionKey) {
			$privateKeyPem = $this->encryptionKey->getPrivateKeyPem();
			$decrypted = json_decode($this->jwt->decrypt($credentialResponse, $privateKeyPem), true);
			$credentialId = array_keys($decrypted['vp_token'])[0];
			$credentialResponse = $decrypted['vp_token'][$credentialId][0];
		}

        $bytes = $this->base64UrlDecode($credentialResponse);
        $rootNode = $this->createCborNode($bytes);

        $response = $rootNode->toPhp();

		$version = (string) ($response['version'] ?? '');
		$status = (int) ($response['status'] ?? -1);

		if ($version !== '1.0') {
			throw new \RuntimeException("Unsupported version: {$version}");
		} else if ($status !== 0) {
			throw new \RuntimeException("DeviceResponse status error: {$status}");
		}

        $docNode = $rootNode->path('documents.0');
        $docPhp  = $docNode->toPhp();
        $docType = $docPhp['docType'] ?? null;

        $issuerSignedNode = $rootNode->path('documents.0.issuerSigned');
        $deviceKey = $this->verifyIssuer($issuerSignedNode);

        $devicePemKey = $this->coseKeyToPem($deviceKey);

        $deviceSignedNode = $rootNode->path('documents.0.deviceSigned');
        $this->verifyDevice($deviceSignedNode, $devicePemKey, $docType);

        return [
            'success'  => true,
            'status'   => 0,
            'doc_type' => $docType,
            'data'     => $this->extractDataFromDocument($docPhp),
        ];
    }

    /* ============================================================
     * Issuer verification
     * ============================================================
     */

    private function verifyIssuer(CborNode $issuerSignedNode): array
    {
        $issuerAuthNode = $issuerSignedNode->path('issuerAuth');

        [$prot, $unprot, $payload, $sigRaw] = $issuerAuthNode->toPhp();

        $sigStructure = $this->buildCoseSigStructure('Signature1', $prot, '', $payload);

        $alg = $this->coseAlgToOpenSSL($prot);
        $derSignature = $this->coseSignatureToDer($sigRaw);

		$certChain = $this->extractX5chainDerArray($unprot);
		$leafCertDer = $certChain[0];

        $issuerPemKey = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($leafCertDer), 64)
            . "-----END CERTIFICATE-----\n";

        if (openssl_verify($sigStructure, $derSignature, $issuerPemKey, $alg) !== 1) {
            throw new \RuntimeException('Issuer signature invalid');
        }

        $msoNode = $this->createCborNode($payload)->decodeAsCbor();
        $msoPhp = $msoNode->toPhp();
        
        $keyInfo = $msoPhp['deviceKeyInfo'] ?? [];
        $deviceKey = $keyInfo['deviceKey'] ?? $keyInfo['deviceKeyRef'] ?? null;

        if ($deviceKey === null) {
            throw new \RuntimeException('deviceKey missing');
        }

        $this->verifyCertificateChain($certChain);

        return $deviceKey;
    }

    /* ============================================================
     * Device verification (Web / DCAPI profile)
     * ============================================================
     */

    private function verifyDevice(CborNode $deviceSignedNode, string $devicePemKey, ?string $docType): void
    {
        $deviceAuthNode = $deviceSignedNode->path('deviceAuth.deviceSignature');

        [$prot, $_unprot, $payload, $sigRaw] = $deviceAuthNode->toPhp();

        $sessionTranscript = $this->buildDeviceSessionTranscript();

        $namespacesNode = $deviceSignedNode->path('nameSpaces');

        if($docType) {
			// ISO/IEC 18013-5
			$deviceAuthenticationInner = $this->buildCborStr('list', [
				$this->buildCborStr('text', 'DeviceAuthentication'),
				$this->buildCborStr('raw', $sessionTranscript),
				$this->buildCborStr('text', $docType),
				$this->buildCborStr('raw', $namespacesNode->bytes()),
			]);
        } else {
			// DC-API
			$deviceAuthenticationInner = $this->buildCborStr('list', [
				$this->buildCborStr('text', 'DeviceAuthentication'),
				$this->buildCborStr('raw', $sessionTranscript),
				$this->buildCborStr('raw', $namespacesNode->bytes())
			]);
		}
		
		$deviceAuthentication = CborBuilder::tag(24, CborBuilder::bytes($deviceAuthenticationInner));
        $sigStructure = $this->buildCoseSigStructure('Signature1', $prot, '', $deviceAuthentication);
        $derSignature = $this->coseSignatureToDer($sigRaw);
        $alg = $this->coseAlgToOpenSSL($prot);
        
        $this->logDebug('origin', $this->origin);
        $this->logDebug('nonce', $this->nonce);
        $this->logDebug('docType', $docType);
        $this->logDebug('protectedHeaderBytes (hex)', bin2hex($prot));
        $this->logDebug('sessionTranscript (hex)', bin2hex($sessionTranscript));
        $this->logDebug('namespaces (hex)', bin2hex($namespacesNode->bytes()));
        $this->logDebug('deviceAuthentication (hex)', bin2hex($deviceAuthentication));
        $this->logDebug('sigStructure (hex)', bin2hex($sigStructure));
        $this->logDebug('derSignature (hex)', bin2hex($derSignature));
        $this->logDebug('alg', $alg);
        $this->logDebug('devicePemKey', $devicePemKey);

        if (openssl_verify($sigStructure, $derSignature, $devicePemKey, $alg) !== 1) {
            throw new \RuntimeException('Device signature invalid');
        }
    }

    /* ============================================================
     * SessionTranscript (DCAPI)
     * ============================================================
     */

	private function buildDeviceSessionTranscript(): string
	{
		$thumbprint = $this->buildPublicKeyThumbprint();
	
		// OpenID4VP 1.0 (Final, July 2025), Appendix B.2.6.2
		// [ origin, nonce, encryptionKey|null ]
		$handover = $this->buildCborStr('list', [
			$this->buildCborStr('text', $this->origin),
			$this->buildCborStr('text', $this->nonce),
			$this->buildCborStr($thumbprint ? 'bytes' : 'null', $thumbprint)
		]);

		$handoverHash = hash('sha256', $handover, true);

		// OpenID4VP 1.0 (Final, July 2025), Appendix B.2.6.2
		// [ null, null, [ "OpenID4VPDCAPIHandover", BrowserHandoverDataBytes ] ]
		$sessionTranscript = $this->buildCborStr('list', [
			$this->buildCborStr('null'),
			$this->buildCborStr('null'),
			$this->buildCborStr('list', [
				$this->buildCborStr('text', 'OpenID4VPDCAPIHandover'),
				$this->buildCborStr('bytes', $handoverHash)
			])
		]);

		return $sessionTranscript;
	}

	private function buildPublicKeyThumbprint(): string
	{
		if (!$this->encryptionKey) {
			return '';
		}

		$jwk = $this->encryptionKey->getPublicJwk();

		// RFC 7638 canonical JWK
		$canonical = [
			'crv' => 'P-256',
			'kty' => 'EC',
			'x'   => $jwk['x'],
			'y'   => $jwk['y'],
		];

		// JSON without whitespace or escaping
		$json = json_encode($canonical, JSON_UNESCAPED_SLASHES);

		// Return raw 32-byte SHA-256 digest
		return hash('sha256', $json, true);
	}

    /* ============================================================
     * Shared helpers
     * ============================================================
     */

    private function buildCoseSigStructure(string $context, string $protected, string $externalAAD, ?string $payload): string
    {
		$payloadType = is_null($payload) ? 'null' : 'bytes';

        return $this->buildCborStr('list', [
            $this->buildCborStr('text', $context),
			$this->buildCborStr('bytes', $protected),
            $this->buildCborStr('bytes', $externalAAD),
            $this->buildCborStr($payloadType, $payload),
        ]);
    }

	private function coseSignatureToDer(string $sig): string
	{
		if (strlen($sig) !== 64) {
			throw new \RuntimeException('Invalid ECDSA signature length');
		}

		$r = substr($sig, 0, 32);
		$s = substr($sig, 32, 32);

		// Strip leading zeros (DER requires minimal encoding)
		$r = ltrim($r, "\x00");
		$s = ltrim($s, "\x00");

		// Ensure at least one byte
		if ($r === '') $r = "\x00";
		if ($s === '') $s = "\x00";

		// Prepend 0x00 if high bit is set (positive INTEGER)
		if (ord($r[0]) & 0x80) $r = "\x00" . $r;
		if (ord($s[0]) & 0x80) $s = "\x00" . $s;

		$rEnc = "\x02" . $this->derLen(strlen($r)) . $r;
		$sEnc = "\x02" . $this->derLen(strlen($s)) . $s;

		$seq = $rEnc . $sEnc;

		return "\x30" . $this->derLen(strlen($seq)) . $seq;
	}

	private function derLen(int $len): string
	{
		if ($len < 0) {
			throw new \RuntimeException('Invalid DER length');
		}

		if ($len < 128) {
			// Short form
			return chr($len);
		}

		// Long form
		$bytes = '';
		while ($len > 0) {
			$bytes = chr($len & 0xFF) . $bytes;
			$len >>= 8;
		}

		return chr(0x80 | strlen($bytes)) . $bytes;
	}

    private function coseKeyToPem(array $k): string
    {
        $x = $k[-2];
        $y = $k[-3];
        $point = "\x04" . $x . $y;

        $spki =
            "\x30\x59\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" .
            "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07" .
            "\x03\x42\x00" . $point;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64)
            . "-----END PUBLIC KEY-----\n";
    }

	private function coseAlgToOpenSSL(string $protectedBytes): int
	{
		$hdr = ($protectedBytes === '')
			? []
			: CborNode::fromBytes($protectedBytes)->toPhp();

		if (!is_array($hdr) || !isset($hdr[1])) {
			throw new \RuntimeException('Invalid COSE protected header');
		}
		
		$alg = (int) $hdr[1];

		return match ($alg) {
			-7  => OPENSSL_ALGO_SHA256,
			-35 => OPENSSL_ALGO_SHA384,
			-36 => OPENSSL_ALGO_SHA512,
			default => throw new \RuntimeException("Unsupported COSE alg: {$alg}")
		};
	}

	private function extractX5chainDerArray(array $unprotected): array
	{
		$x5 = $unprotected[33] ?? $unprotected['33'] ?? null;

		if (is_string($x5)) {
			return [$x5];
		}

		if (is_array($x5) && $x5 !== []) {
			foreach ($x5 as $cert) {
				if (!is_string($cert)) {
					throw new \RuntimeException('Invalid x5chain entry');
				}
			}
			return $x5;
		}

		throw new \RuntimeException('Invalid x5chain');
	}

	private function verifyCertificateChain(array $certChainDer): void
	{
		if (!$this->trustedRootCerts) {
			return;
		}

		// Create temp files
		$certFile = tempnam(sys_get_temp_dir(), 'mdoc-cert-');
		$caFile   = tempnam(sys_get_temp_dir(), 'mdoc-ca-');

		try {
			// Write leaf + intermediates
			$pemChain = '';
			foreach ($certChainDer as $der) {
				$pemChain .= "-----BEGIN CERTIFICATE-----\n"
					. chunk_split(base64_encode($der), 64)
					. "-----END CERTIFICATE-----\n";
			}
			file_put_contents($certFile, $pemChain);

			// Write trusted roots
			file_put_contents($caFile, implode("\n", $this->trustedRootCerts));

			// Let OpenSSL do real path validation
			$ok = openssl_x509_checkpurpose(
				file_get_contents($certFile),
				X509_PURPOSE_ANY,
				[$caFile]
			);

			if ($ok !== true && $ok !== 1) {
				throw new \RuntimeException('Certificate chain validation failed');
			}
		} finally {
			@unlink($certFile);
			@unlink($caFile);
		}
	}

	private function extractDataFromDocument(array $doc): array
	{
		$out = [];

		foreach ($doc['issuerSigned']['nameSpaces'] ?? [] as $ns => $items) {

			$ns = (string) $ns;

			foreach ($items as $item) {
			
				$node = $this->createCborNode($item);
				$data = $node->toPhp();
				
				$key = $data['elementIdentifier'] ?? null;
				$val = $data['elementValue'] ?? null;

				if(is_string($val) && preg_match('~[^\x20-\x7E\t\r\n]~', $val) > 0) {
					$val = base64_encode($val);
				}

				if(is_string($key) && $key) {
					$out[$ns][$key] = $val;
				}
			}
		}

		return $out;
	}

    private function base64UrlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);

        $out = base64_decode($s, true);
        if ($out === false) {
            throw new \RuntimeException('Invalid base64url');
        }
        return $out;
    }

    private function createCborNode(string $bytes): CborNode
    {
        return CborNode::fromBytes($bytes);
    }

	private function buildCborStr(string $type, mixed $value = null): string
	{
		if($type === 'null') {
			return CborBuilder::{$type}();
		} else {
			return CborBuilder::{$type}($value);
		}
	}
	
	private function logDebug($key, $val): void
	{
		$this->debug[$key] = trim($val);
	}

}


class CborNode
{

    private string $bytes;
    private int $major;
    private ?int $tag;
    private ?int $length;
    private int $headerLen;

    private function __construct(
        string $bytes,
        int $major,
        ?int $tag,
        ?int $length,
        int $headerLen
    ) {
        $this->bytes = $bytes;
        $this->major = $major;
        $this->tag = $tag;
        $this->length = $length;
        $this->headerLen = $headerLen;
    }

    /* ============================================================
     * Construction
     * ============================================================
     */

    public static function fromBytes(string $bytes): self
    {
        return self::decodeAtOffset($bytes, 0);
    }

    /* ============================================================
     * Raw access (crypto-safe)
     * ============================================================
     */

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function majorType(): int
    {
        return $this->major;
    }

    public function tag(): ?int
    {
        return $this->tag;
    }

    public function isTag(int $n): bool
    {
        return $this->tag === $n;
    }

    /* ============================================================
     * Navigation
     * ============================================================
     */

    public function map(string|int $key): self
    {
        $this->assertMajor(5);

        [$len, $p] = $this->containerStart();
        $pairs = ($len === null) ? PHP_INT_MAX : $len;

        for ($i = 0; $i < $pairs; $i++) {
			if ($len === null) {
				if ($p >= strlen($this->bytes)) {
					throw new \RuntimeException("Truncated CBOR input");
				}
				if (ord($this->bytes[$p]) === 0xFF) {
					break;
				}
			}

            $k = self::decodeAtOffset($this->bytes, $p);
            $p += strlen($k->bytes);
            $v = self::decodeAtOffset($this->bytes, $p);
            $p += strlen($v->bytes);

			if (is_int($key)) {
				if (($k->majorType() === 0 || $k->majorType() === 1) && $k->toPhp() === $key) {
					return $v;
				}
			} elseif (is_string($key)) {
				if ($k->majorType() === 3 && $k->toPhp() === $key) {
					return $v;
				}
			}
        }

        throw new \RuntimeException("Map key '{$key}' not found");
    }

    public function list(int $index): self
    {
        $this->assertMajor(4);

        [$len, $p] = $this->containerStart();

        if ($len !== null && $index >= $len) {
            throw new \RuntimeException("List index out of bounds");
        }

        for ($i = 0; ; $i++) {
			if ($len === null) {
				if ($p >= strlen($this->bytes)) {
					throw new \RuntimeException("Truncated CBOR input");
				}
				if (ord($this->bytes[$p]) === 0xFF) {
					break;
				}
			}

            $child = self::decodeAtOffset($this->bytes, $p);
            if ($i === $index) {
                return $child;
            }
            $p += strlen($child->bytes);
        }

        throw new \RuntimeException("List index out of bounds");
    }

    public function path(string $expr): self
    {
        $node = $this;
        foreach (explode('.', $expr) as $p) {
            $node = ctype_digit($p)
                ? $node->list((int)$p)
                : $node->map($p);
        }
        return $node;
    }

    /* ============================================================
     * Tag helpers
     * ============================================================
     */

    public function unwrapTag24(): self
    {
        if ($this->tag !== 24) {
            throw new \RuntimeException('Not tag(24)');
        }
        return self::decodeAtOffset($this->bytes, $this->headerLen);
    }

    /* ============================================================
     * Decode view (never for crypto)
     * ============================================================
     */

    public function toPhp(): mixed
    {
        $stream = \CBOR\StringStream::create($this->bytes);
        $decoder = \CBOR\Decoder::create();
        return self::normalize($decoder->decode($stream));
    }

    private static function normalize(mixed $v): mixed
    {
        if ($v instanceof \CBOR\Tag) {
            return self::normalize($v->getValue());
        }
        if ($v instanceof \CBOR\ByteStringObject ||
            $v instanceof \CBOR\TextStringObject ||
            $v instanceof \CBOR\UnsignedIntegerObject ||
            $v instanceof \CBOR\NegativeIntegerObject) {
            return $v->getValue();
        }
        if ($v instanceof \CBOR\OtherObject\NullObject) return null;
        if ($v instanceof \CBOR\OtherObject\TrueObject) return true;
        if ($v instanceof \CBOR\OtherObject\FalseObject) return false;

        if ($v instanceof \CBOR\ListObject) {
            return array_map(fn($x) => self::normalize($x), iterator_to_array($v));
        }

        if ($v instanceof \CBOR\MapObject) {
            $out = [];
            foreach ($v as $item) {
                $out[self::normalize($item->getKey())] =
                    self::normalize($item->getValue());
            }
            return $out;
        }

        return $v;
    }

	public function decodeAsCbor(): CborNode
	{
		$node = $this;

		while (($node->majorType() === 6 && $node->tag() === 24) || $node->majorType() === 2) {
			if ($node->majorType() === 6 && $node->tag() === 24) {
				$node = $node->unwrapTag24();
			} else if ($node->majorType() === 2) {
				$bytes = $node->toPhp();
				$node = self::fromBytes($bytes);
			}
		}

		return $node;
	}

    /* ============================================================
     * Assertions
     * ============================================================
     */

    private function assertMajor(int $expected): void
    {
        if ($this->major !== $expected) {
            throw new \RuntimeException(
                "Expected CBOR major {$expected}, got {$this->major}"
            );
        }
    }

    /* ============================================================
     * Internal decoding
     * ============================================================
     */

	private static function decodeAtOffset(string $src, int $pos): self
	{
		[$major, $val, $p, $tag, $ai] = self::readHeaderFull($src, $pos);
		$start = $pos;

		// ---- Tags ----
		if ($major === 6) {
			$child = self::decodeAtOffset($src, $p);
			$bytes = substr($src, $start, ($p - $start) + strlen($child->bytes));
			return new self(
				$bytes,
				6,
				$tag,
				null,
				$p - $start
			);
		}

		// ---- Integers (uint / nint) ----
		if ($major === 0 || $major === 1) {
			return new self(
				substr($src, $start, $p - $start),
				$major,
				null,
				null,
				$p - $start
			);
		}

		// ---- Byte string / Text string ----
		if ($major === 2 || $major === 3) {

			// Definite-length string
			if ($val !== null) {
				return new self(
					substr($src, $start, ($p - $start) + $val),
					$major,
					null,
					$val,
					$p - $start
				);
			}

			// Indefinite-length string
			$p2 = $p;
			while (true) {
				if ($p2 >= strlen($src)) {
					throw new \RuntimeException("Truncated CBOR input");
				}

				// Break byte
				if (ord($src[$p2]) === 0xFF) {
					$p2++;
					break;
				}

				// Each chunk MUST be a definite-length string
				[$cMajor, $cLen, $cPos] = self::readHeader($src, $p2);

				if ($cMajor !== $major || $cLen === null) {
					throw new \RuntimeException("Invalid indefinite-length string chunk");
				}

				$p2 = $cPos + $cLen;
			}

			return new self(
				substr($src, $start, $p2 - $start),
				$major,
				null,
				null,
				$p - $start
			);
		}

		// ---- Arrays / Maps ----
		if ($major === 4 || $major === 5) {
			$p2 = $p;

			if ($val === null) {
				// Indefinite-length container
				while (true) {
					if ($p2 >= strlen($src)) {
						throw new \RuntimeException("Truncated CBOR input");
					}
					if (ord($src[$p2]) === 0xFF) {
						$p2++; // consume break
						break;
					}
					$child = self::decodeAtOffset($src, $p2);
					$p2 += strlen($child->bytes);
				}
			} else {
				$count = ($major === 5) ? $val * 2 : $val;
				for ($i = 0; $i < $count; $i++) {
					if ($p2 >= strlen($src)) {
						throw new \RuntimeException("Truncated CBOR input");
					}
					$child = self::decodeAtOffset($src, $p2);
					$p2 += strlen($child->bytes);
				}
			}

			return new self(
				substr($src, $start, $p2 - $start),
				$major,
				null,
				$val,
				$p - $start
			);
		}

		// ---- Simple values & floats ----
		if ($major === 7) {

			// Simple values (including ai=24 extended simple)
			if ($ai < 25) {
				return new self(
					substr($src, $start, $p - $start),
					7,
					null,
					null,
					$p - $start
				);
			}

			// Floats
			if ($ai === 25) $extra = 2;       // float16
			elseif ($ai === 26) $extra = 4;   // float32
			elseif ($ai === 27) $extra = 8;   // float64
			else {
				throw new \RuntimeException("Unsupported CBOR simple/float value");
			}

			return new self(
				substr($src, $start, ($p - $start) + $extra),
				7,
				null,
				null,
				$p - $start
			);
		}

		throw new \RuntimeException("Unsupported CBOR major {$major}");
	}

	private static function readHeaderFull(string $b, int $p): array
	{
		if ($p >= strlen($b)) {
			throw new \RuntimeException("Truncated CBOR input");
		}

		$i = ord($b[$p++]);
		$major = $i >> 5;
		$ai = $i & 31;

		if ($ai < 24) {
			$val = $ai;
		} elseif ($ai === 24) {
			if ($p >= strlen($b)) {
				throw new \RuntimeException("Truncated CBOR additional info");
			}
			$val = ord($b[$p]);
			$p += 1;
		} elseif ($ai === 25) {
			$val = unpack('n', substr($b, $p, 2))[1];
			$p += 2;
		} elseif ($ai === 26) {
			$val = unpack('N', substr($b, $p, 4))[1];
			$p += 4;
		} elseif ($ai === 27) {
			$val = unpack('J', substr($b, $p, 8))[1];
			$p += 8;
		} elseif ($ai === 31) {
			$val = null;
		} else {
			throw new \RuntimeException("Unsupported additional info");
		}

		$tag = ($major === 6) ? $val : null;

		// IMPORTANT: return $ai as well
		return [$major, $val, $p, $tag, $ai];
	}

    private function containerStart(): array
    {
        return [$this->length, $this->headerLen];
    }

}


class CborBuilder
{

    /** Pass-through for already-encoded CBOR items */
    public static function raw(string $cborItemBytes): string
    {
        return $cborItemBytes;
    }

    public static function null(): string
    {
        return "\xF6";
    }

    public static function text(string $s): string
    {
        $len = strlen($s);
        return self::encodeTypeAndLen(3, $len) . $s;
    }

	public static function int(int $n): string
	{
		if ($n >= 0) {
			return self::encodeTypeAndLen(0, $n);
		}
		return self::encodeTypeAndLen(1, -1-$n);
	}

    public static function bytes(string $b): string
    {
        $len = strlen($b);
        return self::encodeTypeAndLen(2, $len) . $b;
    }

    /** Encode a CBOR tag (major type 6) */
    public static function tag(int $tagNumber, string $encodedItem): string
    {
        if ($tagNumber < 0) {
            throw new \RuntimeException('CBOR tag number must be non-negative');
        }
        if (!is_string($encodedItem)) {
            throw new \RuntimeException('CborBuilder::tag expects encoded CBOR item');
        }

        return self::encodeTypeAndLen(6, $tagNumber) . $encodedItem;
    }

    /** @param string[] $encodedItems */
    public static function list(array $encodedItems): string
    {
        $out = self::encodeTypeAndLen(4, count($encodedItems));

        foreach ($encodedItems as $item) {
            if (!is_string($item)) {
                throw new \RuntimeException(
                    'CborBuilder::list expects encoded CBOR byte strings'
                );
            }
            $out .= $item;
        }

        return $out;
    }

    /**
     * @param array<int, array{0:string,1:string}> $pairs
     *        Each pair is [encodedKey, encodedValue]
     */
    public static function map(array $pairs): string
    {
        $out = self::encodeTypeAndLen(5, count($pairs));

        foreach ($pairs as $pair) {
            if (!is_array($pair) || count($pair) !== 2) {
                throw new \RuntimeException(
                    'CborBuilder::map expects pairs of [encodedKey, encodedValue]'
                );
            }

            [$k, $v] = $pair;

            if (!is_string($k) || !is_string($v)) {
                throw new \RuntimeException(
                    'CborBuilder::map keys and values must be encoded CBOR strings'
                );
            }

            $out .= $k . $v;
        }

        return $out;
    }

    /**
     * Encode initial byte(s) for major type + length using
     * canonical (minimal) CBOR encoding.
     *
     * major: 0..7
     * len:   0..(2^64 - 1)
     */
    private static function encodeTypeAndLen(int $major, int $len): string
    {
        if ($major < 0 || $major > 7) {
            throw new \RuntimeException("Invalid CBOR major type: {$major}");
        }
        if ($len < 0) {
            throw new \RuntimeException("Invalid CBOR length: {$len}");
        }

        $mt = $major << 5;

        if ($len < 24) {
            return chr($mt | $len);
        }
        if ($len <= 0xFF) {
            return chr($mt | 24) . chr($len);
        }
        if ($len <= 0xFFFF) {
            return chr($mt | 25) . pack('n', $len);
        }
        if ($len <= 0xFFFFFFFF) {
            return chr($mt | 26) . pack('N', $len);
        }

        // 64-bit length (canonical)
        $hi = intdiv($len, 0x100000000);
        $lo = $len % 0x100000000;

        return chr($mt | 27) . pack('NN', $hi, $lo);
    }

}