<?php

namespace Prototypr;

class ApiUi {

	protected $formJsLoaded = false;

	use ConstructTrait;

	public function table($endpoint, array $query = []) {
		//set vars
		$table = '';
		$method = 'GET';
		$query = $this->formatQuery($query);
		$endpoint = $this->formatEndpoint($endpoint);
		$name = $this->getNameFromEndpoint($endpoint);
		//get records
		$data = $this->httpCall($endpoint, $method, $query);
		$records = isset($data['records']) ? $data['records'] : [];
		//create table object
		$table = $this->kernel->table($name, $records);
		//return
		return $this->kernel->event('api.table', $table);
	}

	public function form($endpoint, $method, array $query = [], $loadJs = true) {
		//set vars
		$method = $this->formatMethod($method);
		$query = $this->formatQuery($query, $method);
		$endpoint = $this->formatEndpoint($endpoint);
		$name = $this->getNameFromEndpoint($endpoint);
		//get description
		$data = $this->httpCall($endpoint, $method, [ 'describe' => $method ]);
		//create form object
		$form = $this->kernel->form($name);
		//add data-endpoint attr
		$form->attr('data-endpoint', $endpoint);
		$form->attr('data-method', $method);
		//add query json?
		if(!empty($query)) {
			$form->attr('data-query', $query);
		}
		//add input fields
		foreach($data['input_schema'] as $field => $meta) {
			//set defaults
			$opts = [
				'label' => '',
				'desc' => '',
				'type' => '',
				'default' => null,
				'required' => false,
				'options' => [],
			];
			//hydrate opts
			foreach($opts as $k => $v) {
				//has option?
				if(isset($meta[$k])) {
					$opts[$k] = $meta[$k];
				}
			}
			//format type
			$opts['type'] = explode('.', $opts['type']);
			$opts['type'] = isset($opts['type'][1]) ? $opts['type'][1] : $opts['type'][0];
			//translate type?
			switch($opts['type']) {
				case 'integer':
				case 'boolean':
					$opts['type'] = 'number';
					break;
				case 'string':
					$opts['type'] = 'text';
					break;
			}
			//add input field
			$form->input($field, $opts);
		}
		//add submit
		$form->submit('Save');
		//add form js?
		if($loadJs && !$this->formJsLoaded) {
			//mark as loaded
			$this->formJsLoaded = true;
			//add inline code
			$form->inline('script', $this->formJs());
		}
		//return
		return $this->kernel->event('api.form', $form);
	}

	public function formJs() {
		//buffer
		ob_start();
?>
document.addEventListener('DOMContentLoaded', function() {

	document.querySelectorAll('form[data-endpoint]').forEach(function(form) {

		form.handleErrors = function(response) {
			if(!response || (!response.data && !response.errors)) {
				console.log('failed', response);
				form.addError("Form data failed to load. Please refresh the page.");
				return false;
			}
			if(response.errors) {
				for(var i in response.errors) {
					form.addError(i, response.errors[i]);
				}
				return false;
			}
			return true;
		};

		form.addError = function(field, errors) {
			var el = form;
			if(!errors) {
				errors = field;
				field = null;
			}
			if(!errors || typeof errors === 'string') {
				errors = errors ? [ errors ] : [];
			}
			if(field) {
				el = form.querySelector(".field." + field) || form;
			}
			var container = el.querySelector(".error");
			if(!container) {
				container = document.createElement('div');
				container.classList.add("notice", "error");
				if(el === form) {
					el.insertBefore(container, el.firstChild);
				} else {
					el.appendChild(container);
				}
			}
			errors.forEach(function(msg) {
				var err = document.createElement('div');
				err.classList.add("item");
				err.innerHTML = msg;
				container.appendChild(err);
			});
		};

		form.clearErrors = function() {
			form.querySelectorAll(".error").forEach(function(el) {
				el.parentNode.removeChild(el);
			});
		};

		var endpoint = form.getAttribute('data-endpoint');
		var method = form.getAttribute('data-method').toUpperCase();
		var query = JSON.parse(form.getAttribute('data-query') || '{}');
		
		if(Object.keys(query).length) {

			form.clearErrors();

			fetch(endpoint + "?" + (new URLSearchParams(query)), {
				method: "GET"
			}).then(function(response) {
				return response.json();
			}).then(function(response) {
				if(!form.handleErrors(response)) {
					return;
				}
				var record = Object.values(response.data.records)[0] || {};
				for(var i in record) {
					if(form[i]) {
						//TO-DO: Allow for population of more complex fields (radios, checkboxes etc)
						form[i].value = record[i];
					}
				}
			}).catch(function(error) {
				form.handleErrors();
			});

		}

		form.addEventListener("submit", function(e) {

			e.preventDefault();

			var bodyParams = "";
			var formData = new FormData(this);
			var queryParams = new URLSearchParams(query);

			if(method === "POST" || method === "PUT") {
				bodyParams = new URLSearchParams(formData);
			} else {
				for(var pair of formData.entries()) {
					queryParams.set(pair[0], pair[1]);
				}
			}

			form.clearErrors();

			fetch(endpoint + "?" + queryParams, {
				method: method,
				body: bodyParams,
				headers: {
					"Content-Type": "application/x-www-form-urlencoded"
				}
			}).then(function(response) {
				return response.json();
			}).then(function(response) {
				if(!form.handleErrors(response)) {
					return;
				}
				console.log('success', response);
			}).catch(function(error) {
				form.handleErrors();
			});

		});

	});

});
<?php
		//return
		return trim(ob_get_clean());
	}

	protected function formatMethod($method, $query = null) {
		//format method
		$method = strtoupper($method);
		//update method?
		if($query && $method === 'POST') {
			$method = 'PUT';
		}
		//return
		return $method;
	}

	protected function formatQuery(array $query) {
		//loop through array
		foreach($query as $k => $v) {
			if(empty($v)) {
				unset($query[$k]);
			}
		}
		//return
		return $query;	
	}

	protected function formatEndpoint($endpoint) {
		return trim(explode('?', $endpoint)[0], '/');	
	}

	protected function getNameFromEndpoint($endpoint) {
		//parse endpoint
		$segments  = explode('/', $endpoint);
		//last segment
		return array_pop($segments);
	}

	protected function httpCall($endpoint, $method, array $queryParams) {
		//build url
		$url = $this->kernel->url($endpoint, [ 'query' => $queryParams ]);
		//http call
		$result = $this->kernel->http($url);
		//valid json response?
		if(!$result || !is_array($result) || !isset($result['data'])) {
			throw new \Exception('API endpoint data not found');
		}
		//valid method?
		if(isset($result['data']['methods']) && !in_array($method, $result['data']['methods'])) {
			throw new \Exception('API endpoint does not accept ' . $method . ' request method');
		}
		//return
		return $result['data'];
	}

}