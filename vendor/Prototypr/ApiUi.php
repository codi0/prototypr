<?php

namespace Prototypr;

class ApiUi {

	use ConstructTrait;

	protected $formJsLoaded = false;

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

	public function form($endpoint, $method, array $query = [], array $opts = []) {
		//set opts
		$opts = array_merge([
			'js' => true,
			'cache' => $this->kernel->isEnv('dev') ? null : 3600,
		], $opts);
		//set vars
		$query = $this->formatQuery($query);
		$endpoint = $this->formatEndpoint($endpoint);
		$method = $this->formatMethod($method, $query);
		$name = $this->getNameFromEndpoint($endpoint);
		//get form schema?
		if(!$data = $this->httpCall("$endpoint/schema.$method", $method, [], $opts['cache'])) {
			return null;
		}
		//create form object
		$form = $this->kernel->form($name);
		//add data-endpoint attr
		$form->attr('data-endpoint', $endpoint);
		$form->attr('data-method', $method);
		//add query json?
		if(!empty($query)) {
			$form->attr('data-query', $query);
		}
		//build form fields
		$fields = $this->buildFormFields($data['input_schema']);;
		//add fields
		foreach($fields as $field => $meta) {
			$form->input($field, $meta);
		}
		//add submit
		$form->submit('Save');
		//add form js?
		if($opts['js'] && !$this->formJsLoaded) {
			//mark as loaded
			$this->formJsLoaded = true;
			//add inline js
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

	if(!Object.prototype.forEach) {
		Object.prototype.forEach = function(fn) {
			for(var i in this) {
				if(this.hasOwnProperty(i)) {
					fn(this[i], i, this);
				}
			}
		};
	}

	var ucfirst = function(str) {
		return str[0].toUpperCase() + str.substring(1);
	};

	var getType = function(o) {
		return Object.prototype.toString.call(o).replace('[object ', '').replace(']', '').toLowerCase();
	};

	var getKeys = function(value, keys, callback) {
		var keys = keys || [];
		var type = getType(value);
		if(type === 'object' || type === 'array') {
			value.forEach(function(v, k) {
				var newKeys = [ ...keys ];
				newKeys.push(k)
				getKeys(v, newKeys, callback);
			});
		} else {
			callback(value, keys);
		}
	};

	document.querySelectorAll('form[data-endpoint]').forEach(function(form) {

		var counter = 0;
		var endpoint = form.getAttribute('data-endpoint');
		var method = form.getAttribute('data-method').toUpperCase();
		var query = JSON.parse(form.getAttribute('data-query') || "{}");
		var contentType = form.getAttribute('data-content-type') || "application/x-www-form-urlencoded";

		var handlePrefill = function(value, keys) {
			return getKeys(value, keys, function(value, keys) {
				var group = keys.shift();
				var field = group;
				keys.forEach(function(k) {
					field += '[' + k + ']';
				});
				if(form[field] || cloneFieldset(group)) {
					form[field].value = value || '';
				}
			});
		};

		var handleErrors = function(response) {
			var result = false;
			if(response && (response.data || response.errors)) {
				getKeys(response.errors || {}, [], function(error, keys) {
					keys.pop();
					var field = keys.join('-').replace('_', '-');
					var name = ucfirst(keys[keys.length-1].replace('_', ' '));
					addNotice("error", field, name + ' ' + error);
					result = true;
				});
			} else {
				addNotice("error", "Form data failed to load. Please refresh the page.");
				result = true;
			}
			return result;
		};

		var addNotice = function(type, field, messages) {
			var el = form;
			var container = el.querySelector("form > .notice." + type);
			if(!messages) {
				messages = field;
				field = null;
			}
			if(!messages || typeof messages === 'string') {
				messages = messages ? [ messages ] : [];
			}
			if(field) {
				var t1 = form.querySelector(".field." + field);
				if(t1) {
					el = t1;
					var t2 = el.querySelector(".notice." + type);
					if(t2) {
						container = t2;
					}
				}
			}
			if(!container) {
				container = document.createElement('div');
				container.classList.add("notice", type);
				if(el === form) {
					el.insertBefore(container, el.firstChild);
				} else {
					el.appendChild(container);
				}
			}
			messages.forEach(function(msg) {
				var item = document.createElement('div');
				item.classList.add("item");
				item.innerHTML = msg;
				container.appendChild(item);
			});
			form.querySelector('.notice').scrollIntoView();
		};

		var clearNotices = function() {
			form.querySelectorAll(".notice").forEach(function(el) {
				el.parentNode.removeChild(el);
			});
		};

		var cloneFieldset = function(name) {
			var link = form.querySelector('.add-item.' + name);
			link && link.click();
			return !!link;
		};

		var hideForm = function(hide) {
			form.style.visibility = hide ? 'hidden' : 'visible';
		};

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

			clearNotices();

			fetch(endpoint + "?" + queryParams, {
				method: method,
				body: bodyParams,
				headers: {
					"Content-Type": contentType
				}
			}).then(function(response) {
				return response.json();
			}).then(function(response) {
				if(!handleErrors(response)) {
					addNotice("success", "Form saved successfully");
				}
			}).catch(function(error) {
				handleErrors();
				console.log(error);
			});

		});

		form.querySelectorAll('fieldset[data-multiple]').forEach(function(fieldset) {
		
			var fName = fieldset.getAttribute('data-name');
			var fNameAlt = fName.replace(/e?s$/, '');
			var fLegend = fieldset.querySelector('legend');
			var fLink = document.createElement('div');
			fLink.classList.add('add-item', fName);
			fLink.innerHTML = '<a>Add another ' + fNameAlt + ' &raquo;</a>';
			fieldset.parentNode.insertBefore(fLink, fieldset.nextSibling);
			
			if(fLegend) {
				fLegend.innerHTML = (fNameAlt.charAt(0).toUpperCase() + fNameAlt.slice(1)) + ' #' + (++counter);
			}

			fLink.addEventListener('click', function(e) {

				e.preventDefault();
				
				var num = document.querySelectorAll('fieldset[data-name="' + fName + '"]').length;
				var copy = this.previousSibling.cloneNode(true);
				copy.innerHTML = copy.innerHTML.replaceAll('[' + (num-1) + ']', '[' + num + ']').replaceAll('-' + (num-1) + '-', '-' + num + '-').replace('#' + counter, '#' + (++counter));
				this.parentNode.insertBefore(copy, this);

			});

		});

		if(Object.keys(query).length) {

			hideForm(true);
			clearNotices();

			fetch(endpoint + "?" + (new URLSearchParams(query)), {
				method: "GET"
			}).then(function(response) {
				return response.json();
			}).then(function(response) {
				if(!handleErrors(response)) {
					var record = Object.values(response.data.records)[0];
					handlePrefill(record);
				}
				hideForm(false);
			}).catch(function(error) {
				handleErrors();
				hideForm(false);
				console.log(error);
			});

		}

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

	protected function buildFormFields(array $schema, $parent='') {
		//set vars
		$fields = [];
		//add input fields
		foreach($schema as $field => $meta) {
			//set vars
			$count = 0;
			$isMultiple = isset($meta['multiple']) && $meta['multiple'];
			$hasChildren = isset($meta['children']) && $meta['children'];
			//has children?
			if($hasChildren) {
				//get parent name
				$pName = $parent . ($parent ? '.' : '') . $field . (!$parent && $isMultiple ? '.0' : '');
				//process child fields
				$cFields = $this->buildFormFields($meta['children'], $pName);
				//merge into fields
				$fields = array_merge($fields, $cFields);
				//next
				continue;
			}
			//set defaults
			$opts = [
				'label' => '',
				'desc' => '',
				'fieldset' => $parent ? explode('.', $parent)[0] : '',
				'fieldset_attr' => (strpos($parent, '.0') > 0) ? [ 'data-multiple' => true ] : [],
				'type' => '',
				'default' => null,
				'required' => false,
				'options' => [],
				'before' => '',
				'after' => '',
			];
			//opts to translate
			$translate = [
				'desc' => 'title',
				'default' => 'value',
			];
			//hydrate opts
			foreach($opts as $k => $v) {
				//has option?
				if(isset($meta[$k])) {
					$opts[$k] = $meta[$k];
				}
				//translate key?
				if(isset($translate[$k])) {
					$opts[$translate[$k]] = $opts[$k];
					unset($opts[$k]);
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
			//build full name
			$name = $parent . ($parent ? '.' : '') . $field;
			//add field
			$fields[$name] = $opts;
		}
		//return
		return $fields;
	}

	protected function httpCall($endpoint, $method, array $queryParams = [], $cacheExpiry = null) {
		//build url
		$url = $this->kernel->url($endpoint, [ 'query' => $queryParams ]);
		//create memoized http call
		$http = $this->kernel->memoize('kernel.http', function($url, $method) {
			//make http call
			$result = $this->kernel->http($url);
			//valid json response?
			if(!$result || !is_array($result) || !isset($result['data'])) {
				throw new \Exception('API endpoint not found');
			}
			//valid method?
			if(isset($result['data']['methods']) && $result['data']['methods']) {
				if(!in_array($method, $result['data']['methods'])) {
					throw new \Exception('API endpoint does not accept ' . $method . ' request method');
				}
			}
			//valid schema call?
			if(strpos($url, '/schema.') !== false) {
				//has input schema?
				if(!isset($result['data']['input_schema']) || !$result['data']['input_schema']) {
					throw new \Exception('API schema not found');
				}
			}
			//return
			return $result['data'];
		}, $cacheExpiry);
		//return
		return $http($url, $method);
	}

}