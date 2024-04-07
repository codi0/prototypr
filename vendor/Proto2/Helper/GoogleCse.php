<?php

namespace Proto2\Helper;

class GoogleCse {

	protected $baseUrl = 'https://customsearch.googleapis.com/customsearch/v1';

	protected $searchType = "";
	protected $cseId = "";
	protected $apiKey = "";

	public function __construct(array $opts=[]) {
		//loop through opts
		foreach($opts as $k => $v) {
			//set property?
			if(property_exists($this, $k)) {
				$this->$k = $v;
			}
		}
	}

	public function engine($type) {
		$this->searchType = $type;
		return $this;
	}

	public function search($query, $page=1, array $params=[]) {
		//is array?
		if(is_array($query)) {
			$params = $query;
			$query = "";
		}
		//format params
		$params = array_merge([
			'q' => $query,
			'searchType' => $this->searchType,
			'start' => (($page-1) * 10) + 1,
			'safe' => 'active',
			'cx' => $this->cseId,
			'key' => $this->apiKey,
		], $params);
		//build url
		$url = $this->baseUrl . '?' . http_build_query($params);
		//valid request?
		ob_start();
		$response = file_get_contents($url);
		ob_get_clean();
		//has response?
		if(!$response) {
			return [
				'code' => 500,
				'errors' => [ 'Unable to request image search' ],
			];
		}
		//decode response
		$response = json_decode($response, true);
		//has error?
		if(isset($response['error'])) {
			return [
				'code' => $response['error']['code'],
				'errors' => [ $response['error']['message'] ],
			];
		}
		//return
		return [
			'code' => 200,
			'data' => $response,
		];
	}

}