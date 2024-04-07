<?php

namespace Proto2\Helper;

class ChatGpt {

	protected $apiUrl = "https://api.openai.com/v1/";
	protected $apiKey = "";
	protected $mock = false;

	protected $models = [
		'chat' => 'gpt-3.5-turbo',
		'text' => 'gpt-3.5-turbo',
		'codex' => 'code-davinci-002',
		'audio' => 'whisper-1',
		'finetune' => 'davinci',
		'embeddings' => 'text-embedding-ada-002',
		'moderation' => 'text-moderation-latest',
	];

	protected $tokens = [
		'text-davinci-003' => 4000,
		'text-davinci-002' => 4000,
		'code-davinci-002' => 8000,
	];

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
		//has api key?
		if(empty($this->apiKey)) {
			throw new \Exception("API key required");
		}
	}

	public function mock($useMock) {
		//set property
		$this->mock = (bool) $useMock;
		//chain it
		return $this;
	}

	public function getModel($name) {
		return isset($this->models[$name]) ? $this->models[$name] : '';
	}

	public function restResponse($action, $method=null) {
		//set vars
		$response = null;
		$action = str_replace('/', '', ucwords($action, '/'));
		$method = strtoupper($method ?: $_SERVER['REQUEST_METHOD']);
		//select action
		if($method === 'GET') {
			$action = ($_GET ? 'retrieve' : 'list') . ucfirst($action);
		} else if($method === 'POST') {
			$action = 'create' . ucfirst($action);
		} else if($method === 'PUT') {
			$action = 'edit' . ucfirst($action);
		} else if($method === 'DELETE') {
			$action = 'delete' . ucfirst($action);
		} else {
			$action = null;
		}
		//method exists?
		if($action && method_exists($this, $action)) {
			//set vars
			$args = [];
			$input = array_merge($_GET, $_POST);
			//use reflection
			$refMethod = new \ReflectionMethod($this, $action);
			$refParams = $refMethod->getParameters();
			//build args array
			foreach($refParams as $p) {
				if($p->name === 'opts') {
					$args[] = $input;
				} else if(isset($input[$p->name])) {
					$args[] = $input[$p->name];
				} else {
					$args[] = "";
				}
			}
			//get response
			$response = $this->$action(...$args);
		}
		//return
		return $this->formatResponse($response);
	}

	public function listModels() {
		return $this->httpRequest("models", "GET");
	}

	public function retrieveModel($model) {
		return $this->httpRequest("models/$model", "GET");
	}

	public function createText($prompt, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"prompt" => $prompt,
			"model" => $this->models['text'],
			"max_tokens" => 0,
		], $opts);
		//use chat method?
		if($opts['model'] === $this->models['chat']) {
			//unset opts
			unset($opts['prompt'], $opts['max_tokens']);
			//delegate to chat
			$result = $this->createChat($prompt, $opts);
			//format result?
			if($result && isset($result['choices'])) {
				//loop through choices
				foreach($result['choices'] as $k => $v) {
					//convert to text?
					if(isset($v['message'])) {
						//add text key
						$result['choices'][$k]['text'] = $v['message']['content'];
						//remove message key
						unset($result['choices'][$k]['message']);
					}
				}
			}
			//return
			return $result;
		}
		//calc max tokens?
		if(!$opts['max_tokens']) {
			$opts['max_tokens'] = $this->calcMaxTokens($opts['model'], $opts['prompt']);
		}
		//return
		return $this->httpRequest("completions", "POST", $opts);
	}

	public function editText($input, $instruction, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"input" => $input, 
			"instruction" => $instruction,
			"model" => $this->models['text'],
		], $opts);
		//return
		return $this->httpRequest("edits", "POST", $opts);
	}

	public function createChat($messages, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"messages" => $messages,
			"model" => $this->models['chat'],
		], $opts);
		//format messages?
		if(is_string($opts['messages'])) {
			$opts['messages'] = [[
				'role' => 'user',
				'content' => $opts['messages'],
			]];
		}
		//return
		return $this->httpRequest("chat/completions", "POST", $opts);
	}

	public function createCode($prompt, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"model" => $this->models['codex'],
		], $opts);
		//return
		return $this->createText($prompt, $opts);
	}

	public function createImage($prompt, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"prompt" => $prompt,
		], $opts);
		//return
		return $this->httpRequest("images/generations", "POST", $opts);
	}

	public function createImageVariation($image, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"image" => $image, 
		], $opts);
		//return
		return $this->httpRequest("images/variations", "POST", $opts);
	}

	public function editImage($image, $prompt, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"image" => $image, 
			"prompt" => $prompt,
		], $opts);
		//return
		return $this->httpRequest("images/edits", "POST", $opts);
	}

	public function createAudioTranscription($file, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"file" => $file,
			"model" => $this->models['audio'],
		], $opts);
		//return
		return $this->httpRequest("audio/transcriptions", "POST", $opts);
	}

	public function createAudioTranslation($file, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"file" => $file,
			"model" => $this->models['audio'],
		], $opts);
		//return
		return $this->httpRequest("audio/translations", "POST", $opts);
	}

	public function createEmbeddings($input, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"input" => $input,
			"model" => $this->models['embeddings'],
		], $opts);
		//return
		return $this->httpRequest("embeddings", "POST", $opts);
	}

	public function listFiles() {
		return $this->httpRequest("files", "GET");
	}

	public function retrieveFile($file) {
		return $this->httpRequest("files/$file", "GET");
	}

	public function retrieveFileContent($file) {
		return $this->httpRequest("files/$file/content", "GET");
	}

	public function createFile($file, $purpose, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"file" => $file,
			"purpose" => $purpose,
		], $opts);
		//return
		return $this->httpRequest("files", "POST", $opts);
	}

	public function deleteFile($file) {
		return $this->httpRequest("files/$file", "DELETE");
	}

	public function listFinetunes() {
		return $this->httpRequest("fine-tunes", "GET");
	}

	public function retrieveFinetune($finetune) {
		return $this->httpRequest("fine-tunes/$finetune", "GET");
	}

	public function createFinetune($file, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"training_file" => $file,
			"model" => $this->models['finetune'],
		], $opts);
		//return
		return $this->httpRequest("fine-tunes", "POST", $opts);
	}

	public function cancelFinetune($finetune) {
		return $this->httpRequest("fine-tunes/$finetune/cancel", "POST");
	}

	public function deleteFinetuneModel($model) {
		return $this->httpRequest("models/$model", "DELETE");
	}

	public function createModeration($input, array $opts=[]) {
		//default opts
		$opts = array_merge([
			"input" => $input,
			"model" => $this->models['moderation'],
		], $opts);
		//return
		return $this->httpRequest("moderations", "POST", $opts);
	}

	protected function httpRequest($endpoint, $method, array $body=[]) {
		//set vars
		$response = null;
		$endpoint = trim($endpoint, "/");
		$url = $this->apiUrl . $endpoint;
		//mock data?
		if($this->mock) {
			$response = $this->generateMockData($endpoint, $method, $body);
		} else {
			//create curl
			$curl = curl_init($url);
			//set opts
			curl_setopt_array($curl, [
				CURLOPT_TIMEOUT => 90,
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_FOLLOWLOCATION => 1,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_POSTFIELDS => $body ? json_encode($body) : "",
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_HTTPHEADER => [
					"Content-Type: application/json",
					"Authorization: Bearer $this->apiKey",
				],
			]);
			//get response
			if($response = curl_exec($curl)) {
				$response = json_decode($response, true);
			}
			//has error?
			if($error = curl_error($curl)) {
				$response = null;
			}
		}
		//return
		return $this->formatResponse($response);
	}

	protected function calcMaxTokens($model, $input, $ratio=3.5) {
		//get max for model
		$max = isset($this->tokens[$model]) ? $this->tokens[$model] : 2048;
		//return
		return ($max - intval(strlen($input) / $ratio));
	}

	protected function formatResponse($response) {
		//format response
		if(!$response) {
			$response = [ 'code' => 404 ];
		} else if(isset($response['error'])) {
			//select code
			if($response['error']['code'] === 'invalid_api_key') {
				$code = 403;
			} else if($response['error']['type'] === 'invalid_request_error') {
				$code = 400;
			} else {
				$code = 500;
			}
			$response = [ 'code' => $code, 'errors' => [ $response['error'] ] ];
			
		} else if(isset($response['data'])) {
			$response['code'] = 200;
		} else {
			$response = [ 'code' => 200, 'data' => $response ];
		}
		//return
		return $response;
	}

	protected function generateMockData($endpoint, $method, array $body=[], $min=2, $max=5) {
		//set vars
		$output = '';
		$rand = mt_rand($min, $max);
		$line = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Praesent pellentesque, eros at pretium mattis, urna libero egestas nunc, quis mollis odio enim at eros. ';
		//create output
		for($i=0; $i < $rand; $i++) {
			//add line
			$output .= $line;
			//add line break?
			if(mt_rand(1, 2) == 1) {
				$output .= "\n\n";
			}
		}
		//return
		return [
			'data' => [
				'choices' => [
					0 => [
						'text' => trim($output),
					],
				],
			],
		];	
	}

}