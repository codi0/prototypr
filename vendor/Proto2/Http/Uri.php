<?php

namespace Proto2\Http;

//PSR-7 compatible
class Uri {

    protected $scheme = '';
    protected $userInfo = '';
    protected $host = '';
    protected $port;
    protected $path = '';
    protected $pathInfo = null;
    protected $query = '';
    protected $fragment = '';

	public static function createFromGlobals() {
		$isHttps = self::server('HTTPS') ? (self::server('HTTPS') !== 'off') : (self::server('SERVER_PORT') === 443);
		$exp = explode(':', self::server('HTTP_HOST'), 2);
		$host = $exp[0];
		$port = isset($exp[1]) ? $exp[1] : null;
		$uri = new self('');
		$uri = $uri->withScheme($isHttps ? 'https' : 'http');
		$uri = $uri->withHost($host);
		$uri = $uri->withPort($port ? $port : self::server('SERVER_PORT'));
		$uri = $uri->withPath(self::server('REQUEST_URI'));
		$uri = $uri->withQuery(self::server('QUERY_STRING'));
		return $uri;
	}

	private static function server($key) {
		return isset($_SERVER[$key]) ? $_SERVER[$key] : '';
	}

    public function __construct($uri='') {
		if($uri && $parts = parse_url($uri)) {
			$this->scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
			$this->userInfo = (isset($parts['user']) ? $parts['user'] : '') . (isset($parts['pass']) ? ':' . $parts['pass'] : '');
			$this->host = isset($parts['host']) ? strtolower($parts['host']) : '';
			$this->port = isset($parts['port']) ? $this->filterPort($parts['port']) : null;
			$this->path = isset($parts['path']) ? $parts['path'] : '';
			$this->query = isset($parts['query']) ? $parts['query'] : '';
			$this->fragment = isset($parts['fragment']) ? $parts['fragment'] : '';
		}
	}

	public function __toString() {
		$uri = '';
		if($this->scheme) {
			$uri .= $this->scheme . ':';
		}
		if($authority = $this->getAuthority()) {
			$uri .= '//' . $authority;
		}
		if($path = $this->path) {
			if($path[0] !== '/') {
				if($authority) {
					$path = '/' . $path;
				}
			} elseif(isset($path[1]) && $path[1] === '/') {
				if(!$authority) {
					$path = '/' . ltrim($path, '/');
				}
			}
			$uri .= $path;
		}
		if($this->query) {
			$uri .= '?' . $this->query;
		}
		if($this->fragment) {
			$uri .= '#' . $this->fragment;
		}
		return $uri;
	}

	public function getScheme() {
		return $this->scheme;
	}

    public function getAuthority() {
		$authority = $this->host;
		if($this->userInfo) {
			$authority = $this->userInfo . '@' . $authority;
		}
		if($this->port) {
			$authority .= ':' . $this->port;
		}
		return $authority;
	}

	public function getDomain() {
		$uri = '';
		$authority = $this->getAuthority();
		if($this->scheme && $authority) {
			$uri .= $this->scheme . '://' . $authority;
		}
		return $uri;
	}

	public function getUserInfo() {
		return $this->userInfo;
	}

	public function getHost() {
		return $this->host;
	}

	public function getPort() {
		return $this->port;
	}

	public function getPath() {
		return $this->path;
	}

	public function getPathInfo() {
		if($this->pathInfo === null) {
			$basePath = str_replace(self::server('DOCUMENT_ROOT'), '', dirname(self::server('SCRIPT_FILENAME')));
			$this->pathInfo = rtrim(str_replace($basePath, '', $this->path), '/');
		}
		return $this->pathInfo;
	}

	public function getQuery() {
		return $this->query;
	}

	public function getFragment() {
		return $this->fragment;
	}

	public function withScheme($scheme) {
        $this->scheme = $scheme;
		$this->port = $this->filterPort($this->port);
		return $this;
	}

	public function withUserInfo($user, $password=null) {
		$this->userInfo = $user . ($password ? ':' . $password : '');
		return $this;
	}

	public function withHost($host) {
		$this->host = (string) $host;
		return $this;
	}

	public function withPort($port) {
		$this->port = $this->filterPort($port);
		return $this;
	}

	public function withPath($path) {
		$exp = explode('?', $path);
		$this->path = $exp[0];
		return $this;
	}

	public function withPathInfo($pathInfo) {
		$pathInfo = trim($pathInfo, '/');
		$this->pathInfo = $pathInfo ? '/' . $pathInfo : '';
		return $this;
	}

	public function withQuery($query) {
		$this->query = $query;
		return $this;
	}

	public function withFragment($fragment) {
		$this->fragment = $fragment;
		return $this;
	}

	protected function filterPort($port) {
		return ($port && !in_array((int) $port, [ 80, 443 ])) ? (int) $port : null;
    }

}