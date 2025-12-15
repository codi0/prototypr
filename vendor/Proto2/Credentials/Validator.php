<?php

namespace Proto2\Credentials;

use CBOR\Decoder;
use CBOR\Encoder;
use CBOR\StringStream;
use CBOR\AbstractCBORObject;
use CBOR\TextStringObject;
use CBOR\ByteStringObject;
use CBOR\UnsignedIntegerObject;
use CBOR\NegativeIntegerObject;
use CBOR\ListObject;
use CBOR\MapObject;
use CBOR\OtherObject\NullObject;
use CBOR\OtherObject\TrueObject;
use CBOR\OtherObject\FalseObject;
use CBOR\Tag;
use CBOR\Tag\GenericTag;

/**
 * Digital Credentials API Validator
 *
 * STANDARD: https://mobiledl-e5018.web.app/ISO_18013-5_E_draft.pdf?utm_source=chatgpt.com
 * REFERENCE: https://developers.google.com/wallet/identity/verify/accepting-ids-from-wallet-online
 * PHP: 8.1+
 */
class Validator
{

    private string $nonce;
    private string $origin;
    private string $encryptionKey;
    private array $trustedRootCerts = [];

    private RawCborExtractor $extractor;
    private bool $debug = false;

    public function __construct(string $nonce, string $origin, ?string $encryptionKey = null)
    {
        $this->origin = $origin;
        $this->nonce = $this->base64UrlDecode($nonce);
        $this->encryptionKey = $this->base64UrlDecode($encryptionKey ?? '');
    }

    public function loadTrustedRootsFromPemFile(string $pemPath): void
    {
        $pem = file_get_contents($pemPath);
        preg_match_all('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m);
        foreach ($m[0] as $c) {
            $this->trustedRootCerts[] = $c;
        }
    }

    public function validateCredentialResponse(string $credentialResponse): array
    {
        $bytes = $this->base64UrlDecode($credentialResponse);
        $this->extractor = new RawCborExtractor($bytes);

        $root = $this->extractor->decodeRoot();
        $response = $this->extractor->decodeNodeToPhp($root);

        if (($response['version'] ?? null) != '1.0') {
            throw new \RuntimeException('Unsupported version');
        }
        if (($response['status'] ?? null) != 0) {
            throw new \RuntimeException('DeviceResponse status error');
        }

        $docNode = $this->extractor
            ->extractListIndex(
                $this->extractor->extractMapKey($root, 'documents'),
                0
            );

        $doc = $this->extractor->decodeNodeToPhp($docNode);

        $issuerSignedNode = $this->extractor->extractMapKey($docNode, 'issuerSigned');
        $deviceKey = $this->verifyIssuer($issuerSignedNode);
        $devicePemKey = $this->coseKeyToPem($deviceKey);

        $deviceSignedNode = $this->extractor->extractMapKey($docNode, 'deviceSigned');
        $this->verifyDevice($deviceSignedNode, $devicePemKey);

        return [
            'success' => true,
            'status' => 0,
            'doc_type' => $doc['docType'] ?? null,
            'data' => $this->extractDataFromDocument($doc),
        ];
    }

    /* ========================================================
     * ISSUER VERIFICATION
     * ======================================================== */

    private function verifyIssuer(RawCborNode $issuerSignedNode): array
    {
        $issuerAuthNode = $this->extractor->extractMapKey($issuerSignedNode, 'issuerAuth');
        [$prot, $unprot, $payload, $sigRaw] =
            $this->extractor->decodeNodeToPhp($issuerAuthNode);

        $mso = $this->extractor->decodeBytesToPhp($payload, true);
        
        if (!isset($mso['deviceKeyInfo']['deviceKey'])) {
            throw new \RuntimeException('deviceKey missing');
        }

        $sigStruct = $this->buildCoseSigStructure(
            'Signature1',
            $prot,
            '',
            $payload
        );

        $alg = $this->coseAlgToOpenSSL(
            $this->extractor->extractAlgoFromProtected($prot)
        );

        $sigDer = $this->coseSignatureToDer($sigRaw);
        $certDer = $this->extractX5chainFirstCertDer($unprot);

        $pem =
            "-----BEGIN CERTIFICATE-----\n" .
            chunk_split(base64_encode($certDer), 64) .
            "-----END CERTIFICATE-----\n";

        if (openssl_verify($sigStruct, $sigDer, $pem, $alg) !== 1) {
            throw new \RuntimeException('Issuer signature invalid');
        }

		$issuerNSNode = $this->extractor->extractMapKey($issuerSignedNode, 'nameSpaces');
		$this->validateMsoDigests($mso, $issuerNSNode);

        $this->verifyCertificateChain($certDer);

        return $mso['deviceKeyInfo']['deviceKey'];
    }

	private function validateMsoDigests(array $mso, RawCborNode $issuerNameSpacesNode): void
	{
		if (!isset($mso['valueDigests']) || !is_array($mso['valueDigests'])) {
			throw new \RuntimeException('MSO missing valueDigests');
		}

		foreach ($mso['valueDigests'] as $ns => $digests) {
			if (!is_array($digests)) {
				continue;
			}

			// Base namespace = first 5 segments
			$parts = explode('.', $ns);
			if (count($parts) < 5) {
				continue;
			}

			$baseNs = implode('.', array_slice($parts, 0, 5));

			// Raw CBOR array node for issuerSigned.nameSpaces[baseNs]
			$nsNode = $this->extractor->extractMapKey($issuerNameSpacesNode, $baseNs);

			// Parse array header (must be array)
			[$major, $count, $offset] = $this->extractor->readHeader($nsNode->bytes, 0);
			if ($major !== 4) {
				throw new \RuntimeException(
					"IssuerSigned namespace '{$baseNs}' must be an array"
				);
			}

			$pos = $offset;

			for ($i = 0; $i < $count; $i++) {
				// Extract raw tagged item
				$itemNode = $this->extractor->decodeAtOffset($pos, $nsNode->bytes);
				$pos += strlen($itemNode->bytes);

				// Must be tag(24)
				[$maj, $tag] = $this->extractor->readHeader($itemNode->bytes, 0);
				if ($maj !== 6 || $tag !== 24) {
					continue;
				}

				// Decode embedded CBOR (tag 24 payload)
				$decoded = $this->extractor->decodeBytesToPhp($itemNode->bytes);

				if (
					!is_array($decoded) ||
					!isset($decoded['digestID'], $decoded['elementIdentifier'])
				) {
					continue;
				}

				// FULL namespace prefix match
				if (!str_starts_with($decoded['elementIdentifier'], $ns)) {
					continue;
				}

				$digestID = $decoded['digestID'];

				if (!isset($digests[$digestID])) {
					continue;
				}

				// Digest is over the *entire tagged item*
				$actual   = hash('sha256', $itemNode->bytes, true);
				$expected = $digests[$digestID];

				if (!hash_equals($expected, $actual)) {
					throw new \RuntimeException(
						"MSO digest mismatch in namespace '{$ns}', digestID {$digestID}"
					);
				}
			}
		}
	}

	private function extractX5chainFirstCertDer(array $unprotected): string
	{
		// COSE header parameter 33 = x5chain
		if (!array_key_exists(33, $unprotected)) {
			throw new \RuntimeException(
				'x5chain (header 33) not found in unprotected headers for issuerAuth'
			);
		}

		$x5chain = $unprotected[33];

		// x5chain can be a single bstr or an array of bstr
		if (is_string($x5chain)) {
			return $x5chain;
		}

		if (is_array($x5chain) && isset($x5chain[0]) && is_string($x5chain[0])) {
			return $x5chain[0];
		}

		throw new \RuntimeException(
			'Invalid x5chain format in unprotected headers for issuerAuth'
		);
	}

    private function verifyCertificateChain(string $certDer): void
    {
        if (empty($this->trustedRootCerts)) {
            return; // nothing to verify against
        }
        $certPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($certDer), 64) . "-----END CERTIFICATE-----\n";
        $cert = openssl_x509_read($certPem);
        if ($cert === false) throw new \RuntimeException('Failed to read certificate for chain verification');

        foreach ($this->trustedRootCerts as $rootPem) {
            $root = openssl_x509_read($rootPem);
            if ($root === false) continue;
            $result = openssl_x509_checkpurpose($cert, X509_PURPOSE_ANY, [$root]);
            if ($result === true) return;
        }

        // If none matched, throw (but keep cert validity check separate)
        throw new \RuntimeException('Certificate chain did not validate against any trusted root');
    }


    /* ========================================================
     * DEVICE VERIFICATION
     * ======================================================== */

    private function verifyDevice(RawCborNode $deviceSignedNode, string $devicePemKey): void
    {
        $sigNode = $this->extractor
            ->extractMapKey(
                $this->extractor->extractMapKey($deviceSignedNode, 'deviceAuth'),
                'deviceSignature'
            );

        [$prot, $unprot, $payload, $sigRaw] =
            $this->extractor->decodeNodeToPhp($sigNode);

        [$handover, $sessionTranscript] = $this->buildDeviceSessionTranscript();
        $namespaces = $this->extractor->extractMapKey($deviceSignedNode, 'nameSpaces');

        $deviceAuth = $this->buildDeviceAuthStructure(
            $sessionTranscript,
            $namespaces->bytes
        );

        $sigStruct = $this->buildCoseSigStructure(
            'Signature1',
            $prot,
            $deviceAuth,
            null
        );
        
        $sigDer = $this->coseSignatureToDer($sigRaw);

		$alg = $this->coseAlgToOpenSSL(
			$this->extractor->extractAlgoFromProtected($prot)
		);

		if($this->debug) {
			echo "deviceSignatureBytes: " . bin2hex($sigNode->bytes) . "\n";
			echo "protectedBytes: " . bin2hex($prot) . "\n";
			echo "payload: " . bin2hex($payload) . "\n";
			echo "signatureRaw: " . bin2hex($sigRaw) . "\n";
			echo "handoverData: " . bin2hex($handover) . "\n";
			echo "sessionTranscript: " . bin2hex($sessionTranscript) . "\n";
			echo "namesSpaces: " . bin2hex($namespaces->bytes) . "\n";
			echo "deviceAuthentication: " . bin2hex($deviceAuth) . "\n";
			echo "sigStruct: " . bin2hex($sigStruct) . "\n";
			echo "sigStructHash: " . hash('sha256', $sigStruct) . "\n";
			echo "sigDer: " . bin2hex($sigDer) . "\n";
			echo "alg: " . $alg . "\n";
			echo "devicePemKey: " . $devicePemKey . "\n";
			exit();
		}

        if (openssl_verify($sigStruct, $sigDer, $devicePemKey, $alg) !== 1) {
            throw new \RuntimeException('Device signature invalid');
        }
    }

	private function buildDeviceSessionTranscript(): array
	{
		$encodeLen = function(int $major, int $len): string {
			if ($len < 24) {
				return chr(($major << 5) | $len);
			} elseif ($len < 0x100) {
				return chr(($major << 5) | 24) . chr($len);
			} elseif ($len < 0x10000) {
				return chr(($major << 5) | 25)
					. chr(($len >> 8) & 0xff)
					. chr($len & 0xff);
			} elseif ($len < 0x100000000) {
				return chr(($major << 5) | 26)
					. chr(($len >> 24) & 0xff)
					. chr(($len >> 16) & 0xff)
					. chr(($len >> 8) & 0xff)
					. chr($len & 0xff);
			} else {
				$hi = intdiv($len, 0x100000000);
				$lo = $len & 0xffffffff;
				return chr(($major << 5) | 27)
					. chr(($hi >> 24) & 0xff)
					. chr(($hi >> 16) & 0xff)
					. chr(($hi >> 8) & 0xff)
					. chr($hi & 0xff)
					. chr(($lo >> 24) & 0xff)
					. chr(($lo >> 16) & 0xff)
					. chr(($lo >> 8) & 0xff)
					. chr($lo & 0xff);
			}
		};

		$cborText = fn(string $s): string =>
			$encodeLen(3, strlen($s)) . $s;

		$cborBytes = fn(string $s): string =>
			$encodeLen(2, strlen($s)) . $s;

		$cborNull = "\xF6";

		// HandoverData
		$items = $cborText($this->origin)
			   . $cborBytes($this->nonce);

		if ($this->encryptionKey) {
			$items .= $cborBytes($this->encryptionKey);
			$handoverData = $encodeLen(4, 3) . $items;
		} else {
			$handoverData = $encodeLen(4, 2) . $items;
		}

		$handoverHash = hash('sha256', $handoverData, true);

		$handover =
			$encodeLen(4, 2)
		  . $cborText('OpenID4VPDCAPIHandover')
		  . $cborBytes($handoverHash);

		$sessionTranscript =
			$encodeLen(4, 3)
		  . $cborNull
		  . $cborNull
		  . $handover;
		  
		  return [ $handoverData, $sessionTranscript ];
	}

	private function buildDeviceAuthStructure(string $sessionTranscriptCbor, string $deviceNameSpacesCbor): string {
		$enc = new Encoder();
		 return $enc->encode(new ListObject([
			new TextStringObject("DeviceAuthentication"),
			$sessionTranscriptCbor,
			$deviceNameSpacesCbor
		]));
	}

    /* ========================================================
     * SHARED HELPERS
     * ======================================================== */

    private function buildCoseSigStructure(
        string $context,
        string $protected,
        string $externalAAD,
        ?string $payload
    ): string {
        $enc = new Encoder();
        return $enc->encode(new ListObject([
            new TextStringObject($context),
            new ByteStringObject($protected),
            new ByteStringObject($externalAAD),
            new ByteStringObject($payload ?? ''),
        ]));
    }

    private function coseSignatureToDer(string $sig): string
    {
        $r = substr($sig, 0, 32);
        $s = substr($sig, 32, 32);
        if (ord($r[0]) & 0x80) $r = "\x00" . $r;
        if (ord($s[0]) & 0x80) $s = "\x00" . $s;
        return "\x30" . chr(strlen($r) + strlen($s) + 4)
            . "\x02" . chr(strlen($r)) . $r
            . "\x02" . chr(strlen($s)) . $s;
    }

    private function coseKeyToPem(array $k): string
    {
        $x = $k[-2]; $y = $k[-3];
        $point = "\x04" . $x . $y;
        $spki =
            "\x30\x59\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01"
            . "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07"
            . "\x03\x42\x00" . $point;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64)
            . "-----END PUBLIC KEY-----\n";
    }

	private function extractDataFromDocument(array $documentNormalized): array
	{
		$out = [];

		if (!isset($documentNormalized['issuerSigned']['nameSpaces'])) {
			return $out;
		}

		foreach ($documentNormalized['issuerSigned']['nameSpaces'] as $ns => $items) {
			$out[$ns] = [];

			foreach ($items as $item) {
				if (is_array($item) && array_key_exists('elementIdentifier', $item) && array_key_exists('elementValue', $item)) {
					$out[$ns][$item['elementIdentifier']] = $item['elementValue'];
				}
			}
		}

		return $out;
	}

    private function base64UrlDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/'));
    }

    private function coseAlgToOpenSSL(int $alg): int
	{
		return match ($alg) {
			-7  => OPENSSL_ALGO_SHA256, // ES256
			-35 => OPENSSL_ALGO_SHA384, // ES384
			-36 => OPENSSL_ALGO_SHA512, // ES512
			default => throw new \RuntimeException("Unsupported COSE alg: {$alg}")
		};
    }

}



class RawCborExtractor
{

    private string $bytes;
    private Decoder $decoder;

    public function __construct(string $bytes)
    {
        $this->bytes = $bytes;
        $this->decoder = new Decoder();
    }

    public function decodeRoot(): RawCborNode
    {
        return $this->decodeAtOffset(0, $this->bytes);
    }

	public function decodeAtOffset(int $pos, ?string $src = null): RawCborNode
	{
		$src ??= $this->bytes;
		$srcLen = strlen($src);

		if ($pos >= $srcLen) {
			throw new \RuntimeException('CBOR decode offset beyond buffer');
		}

		[$major, $len, $offset] = $this->readHeader($src, $pos);

		/*
		 * Major types 0,1 (unsigned / negative int)
		 * Major type 7 (simple / float)
		 * These are header-only values
		 */
		if (
			$major === 0 ||
			$major === 1 ||
			$major === 7
		) {
			return new RawCborNode(
				substr($src, $pos, $offset - $pos),
				null
			);
		}

		/*
		 * Byte string (bstr)
		 */
		if ($major === 2) {
			if ($len === null) {
				throw new \RuntimeException('Indefinite bstr not supported');
			}

			if ($offset + $len > $srcLen) {
				throw new \RuntimeException('CBOR bstr exceeds buffer');
			}

			$totalLen = ($offset - $pos) + $len;

			return new RawCborNode(
				substr($src, $pos, $totalLen),
				substr($src, $offset, $len)
			);
		}

		/*
		 * Text string (tstr)
		 */
		if ($major === 3) {
			if ($len === null) {
				throw new \RuntimeException('Indefinite tstr not supported');
			}

			if ($offset + $len > $srcLen) {
				throw new \RuntimeException('CBOR tstr exceeds buffer');
			}

			$totalLen = ($offset - $pos) + $len;

			return new RawCborNode(
				substr($src, $pos, $totalLen),
				substr($src, $offset, $len)
			);
		}

		/*
		 * Tag — exactly one child item
		 */
		if ($major === 6) {
			$child = $this->decodeAtOffset($offset, $src);

			return new RawCborNode(
				substr($src, $pos, ($offset - $pos) + strlen($child->bytes)),
				null
			);
		}

		/*
		 * Arrays (4) and Maps (5)
		 */
		if ($major !== 4 && $major !== 5) {
			throw new \RuntimeException("Unsupported CBOR major type {$major}");
		}

		$p = $offset;

		/*
		 * Indefinite-length array/map
		 */
		if ($len === null) {
			while (true) {
				if ($p >= $srcLen) {
					throw new \RuntimeException('CBOR indefinite container not terminated');
				}

				// Break byte
				if (ord($src[$p]) === 0xFF) {
					$p++;
					break;
				}

				$child = $this->decodeAtOffset($p, $src);
				$p += strlen($child->bytes);
			}

			return new RawCborNode(
				substr($src, $pos, $p - $pos),
				null
			);
		}

		/*
		 * Definite-length array/map
		 */
		$count = ($major === 5) ? $len * 2 : $len;

		for ($i = 0; $i < $count; $i++) {
			if ($p >= $srcLen) {
				throw new \RuntimeException('CBOR container exceeds buffer');
			}

			$child = $this->decodeAtOffset($p, $src);
			$p += strlen($child->bytes);
		}

		return new RawCborNode(
			substr($src, $pos, $p - $pos),
			null
		);
	}

    public function extractMapKey(RawCborNode $map, string|int $key): RawCborNode
    {
        $php = $this->decodeNodeToPhp($map);
        if (!isset($php[$key])) throw new \RuntimeException("Map key $key missing");
        $decoded = $this->decoder->decode(StringStream::create($map->bytes));
        foreach ($decoded as $item) {
            if ($this->decodeBytesToPhp($this->encode($item->getKey())) === $key) {
                return new RawCborNode($this->encode($item->getValue()), null);
            }
        }
        throw new \RuntimeException('Key not found');
    }

    public function extractListIndex(RawCborNode $list, int $i): RawCborNode
    {
        $arr = $this->decodeNodeToPhp($list);
        $stream = StringStream::create($list->bytes);
        $obj = $this->decoder->decode($stream);
        return new RawCborNode($this->encode($obj[$i]), null);
    }

    public function decodeNodeToPhp(RawCborNode $n): mixed
    {
        return $this->decodeBytesToPhp($n->bytes);
    }

    public function decodeBytesToPhp(string $b, $test=false): mixed
    {
		$stream = StringStream::create($b);
		$decoded = $this->decoder->decode($stream);
		$php = $this->normalize($decoded);
		return $php;
    }

	private function normalize(mixed $o): mixed
	{
		// ---- TAGGED OBJECTS ----
		if ($o instanceof Tag) {
			$value = $o->getValue();

			// RFC 8949: tag(24) = embedded CBOR
			if ($value instanceof ByteStringObject) {
				return $this->decodeBytesToPhp($value->getValue());
			}

			// Other tags: unwrap value
			return $this->normalize($value);
		}

		// ---- BYTE STRING (NON-TAGGED) ----
		if ($o instanceof ByteStringObject) {
			return $o->getValue();
		}

		// ---- TEXT ----
		if ($o instanceof \CBOR\TextStringObject) {
			return $o->getValue();
		}

		// ---- INTEGERS ----
		if (
			$o instanceof \CBOR\UnsignedIntegerObject ||
			$o instanceof \CBOR\NegativeIntegerObject
		) {
			return $o->getValue();
		}

		// ---- SIMPLE VALUES ----
		if ($o instanceof \CBOR\NullObject) return null;
		if ($o instanceof \CBOR\TrueObject) return true;
		if ($o instanceof \CBOR\FalseObject) return false;

		// ---- ARRAYS ----
		if ($o instanceof \CBOR\ListObject) {
			return array_map(
				fn($v) => $this->normalize($v),
				iterator_to_array($o)
			);
		}

		// ---- MAPS ----
		if ($o instanceof \CBOR\MapObject) {
			$out = [];
			foreach ($o as $item) {
				$out[$this->normalize($item->getKey())] =
					$this->normalize($item->getValue());
			}
			return $out;
		}

		return $o;
	}

    public function extractAlgoFromProtected(string $b): int
    {
        $m = $this->decodeBytesToPhp($b);
        return $m[1] ?? -7;
    }

	public function readHeader(string $b, int $p): array
	{
		$initial = ord($b[$p++]);
		$major = $initial >> 5;
		$ai = $initial & 31;

		// Indefinite length
		if ($ai === 31) {
			return [$major, null, $p];
		}

		if ($ai < 24) {
			return [$major, $ai, $p];
		}

		if ($ai === 24) {
			return [$major, ord($b[$p]), $p + 1];
		}

		if ($ai === 25) {
			return [$major, unpack('n', substr($b, $p, 2))[1], $p + 2];
		}

		if ($ai === 26) {
			return [$major, unpack('N', substr($b, $p, 4))[1], $p + 4];
		}

		if ($ai === 27) {
			$v = unpack('J', substr($b, $p, 8))[1];
			return [$major, $v, $p + 8];
		}

		throw new \RuntimeException('Unsupported CBOR additional info: ' . $ai);
	}

    private function encode(AbstractCBORObject $o): string
    {
        return (new Encoder())->encode($o);
    }

}


class RawCborNode
{

	public string $bytes;
	public string|int|null $value = null;

    public function __construct(string $bytes, string|int|null $value)
    {
		$this->bytes = $bytes;
		$this->value = $value;
    }

}