<?php

namespace Prototypr;

class ApiUi {

	use ConstructTrait;

	protected $formJsLoaded = false;

	public function crud($endpoint, array $opts = []) {
		//format opts
		$opts = array_merge([
			'id' => 'id',
			'action' => 'action',
			'actions' => [ 'edit', 'delete' ],
		], $opts);
		//set vars
		$output = null;
		$id = $this->kernel->input('GET.' . $opts['id']);
		$action = $this->kernel->input('GET.' . $opts['action']);
		//get base url
		$baseUrl = $this->kernel->url(null, [
			'query' => [
				$opts['id'] => null,
				$opts['action'] => null,
				'deleted' => null,
			]
		]);
		//delete?
		if($id && $action === 'delete') {
			//make delete call
			$response = $this->callApi($endpoint, "DELETE", [ $opts['id'] => $id ]);
			//check if delete worked
			$deleted = ($response && isset($response['deleted']) && $response['deleted']);
			//build redirect URL
			$redirectUrl = $this->kernel->url($baseUrl, [
				'query' => [
					'deleted' => $deleted ? 'true' : 'false',
				],
			]);
			//redirect user
			if(headers_sent()) {
				echo '<meta http-equiv="refresh" content="0; url=' . $redirectUrl . '">';
				exit();
			} else {
				header("Location: $redirectUrl");
				exit();
			}
		}
		//update?
		if($id && $action === 'edit') {
			//display form
			$output = $this->form($endpoint, "PUT", array_merge($opts, [
				'back' => $baseUrl,
				'query' => [
					$opts['id'] => $id
				],
			]));
		}
		//add?
		if($action === 'add') {
			//display form
			$output = $this->form($endpoint, "POST", array_merge($opts, [
				'back' => $baseUrl,
			]));
		}
		//list?
		if(empty($output)) {
			//list records
			$output = $this->table($endpoint, $opts);
			//add deleted notice?
			if(isset($_GET['deleted']) && $_GET['deleted']) {
				$deleted = $_GET['deleted'] === 'true';
				$message = $deleted ? 'Record successfully deleted' : 'Unable to delete record';
				$output->before('<div class="notice ' . ($deleted ? 'success' : 'error') . '">' . $message . '</div>');
			}
			//add 'new' button
			$addName = preg_replace('/e?s$/', '', $output->name());
			$addUrl = $this->kernel->url($baseUrl, [ 'query' => [ $opts['action'] => 'add' ] ]);
			$output->before('<a class="button" href="' . $addUrl . '">Add new ' . $addName . '</a>');
			//add row actions
			foreach($opts['actions'] as $action) {
				//build action url
				$actionUrl = $this->kernel->url($baseUrl, [
					'query' => [
						$opts['action'] => $action,
						$opts['id'] => '{' . $opts['id'] . '}',
					]
				]);
				//include action
				$output->addRowAction($action, $actionUrl);
			}
		}
		//return
		return $output;
	}

	public function table($endpoint, array $opts = []) {
		//format opts
		$opts = array_merge([
			'title' => '',
			'query' => [],
			'schema_url' => $this->formatEndpoint($endpoint, 'schema'),
		], $opts);
		//set vars
		$table = '';
		$method = 'GET';
		$fieldOpts = [];
		$query = $this->formatQuery($opts['query']);
		$endpoint = $this->formatEndpoint($endpoint);
		$name = $this->getNameFromEndpoint($endpoint);
		//get schema
		$schema = $this->schema($opts['schema_url']);
		//build field options
		foreach($schema as $k => $v) {
			if(isset($v['options']) && $v['options']) {
				$fieldOpts[$k] = $v['options'];
			}
		}
		//get records
		$data = $this->callApi($endpoint, $method, $query);
		$records = isset($data['records']) ? $data['records'] : [];
		//create table object
		$table = HtmlTable::factory($name);
		//add data
		$table->setData($records);
		//add table title?
		if($opts['title']) {
			//add html tag?
			if($opts['title'] === strip_tags($opts['title'])) {
				$opts['title'] = '<h2 class="title">' . $opts['title'] . '</h2>';
			}
			//add before table
			$table->before($opts['title']);
		}
		//filter cells
		$table->filterCell(function($html, $row, $col) use($fieldOpts) {
			//has field options?
			if(isset($fieldOpts[$col]) && isset($fieldOpts[$col][$html])) {
				$html = $fieldOpts[$col][$html];
			}
			//return
			return $html;
		});
		//return
		return $this->kernel->event('api.table', $table);
	}

	public function form($endpoint, $method, array $opts = []) {
		//format opts
		$opts = array_merge([
			'back' => '',
			'title' => '',
			'query' => [],
			'schema_url' => $this->formatEndpoint($endpoint, 'schema'),
		], $opts);
		//set vars
		$query = $this->formatQuery($opts['query']);
		$endpoint = $this->formatEndpoint($endpoint);
		$method = $this->formatMethod($method, $query);
		$name = $this->getNameFromEndpoint($endpoint);
		//get schema
		$schema = $this->schema($opts['schema_url'], $method);
		//create form object
		$form = HtmlForm::factory($name);
		//add back link?
		if($opts['back']) {
			//add html tag?
			if($opts['back'] === strip_tags($opts['back'])) {
				$opts['back'] = '<div class="back"><a href="' . $opts['back'] . '">&laquo; Go back</a></div>';
			}
			//add before form
			$form->before($opts['back']);
		}
		//add form title?
		if($opts['title']) {
			//add subtitle?
			if(strpos($opts['title'], ':') === false) {
				$opts['title'] .= ': ' . ($opts['query'] ? 'Edit #' . array_values($opts['query'])[0] : 'Add new');
			}
			//add html tag?
			if($opts['title'] === strip_tags($opts['title'])) {
				$opts['title'] = '<h2 class="title">' . $opts['title'] . '</h2>';
			}
			//add before form
			$form->before($opts['title']);
		}
		//add data-endpoint attr
		$form->attr('data-endpoint', $endpoint);
		$form->attr('data-method', $method);
		//add query json?
		if(!empty($query)) {
			$form->attr('data-query', $query);
		}
		//build form fields
		$fields = $this->buildFormFields($schema);
		//add fields
		foreach($fields as $field => $meta) {
			$form->input($field, $meta);
		}
		//add submit
		$form->submit('Save');
		//add form js?
		if(!$this->formJsLoaded) {
			//mark as loaded
			$this->formJsLoaded = true;
			//add after
			$form->after($this->formJs());
		}
		//return
		return $this->kernel->event('api.form', $form);
	}

	public function schema($endpoint, $method = null, $cacheExpiry = 3600) {
		//create memoized http call
		$http = $this->kernel->memoize('schema', function($url) {
			//make http call
			$result = $this->kernel->http($url);
			//valid json response?
			if(!$result || !is_array($result) || !isset($result['data'])) {
				throw new \Exception('API endpoint not found');
			}
			//has input schema?
			if(!isset($result['data']['input_schema']) || !$result['data']['input_schema']) {
				throw new \Exception('API schema not found');
			}
			//return
			return $result['data']['input_schema'];
		}, $cacheExpiry);
		//get response
		$response = $http($endpoint);
		//filter schema?
		if(!empty($method)) {
			$response = Route::filterInputSchema($response, $method);
		}
		//return
		return $response;
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

	protected function formatEndpoint($endpoint, $suffix = null) {
		//remove query string
		$endpoint = trim(explode('?', $endpoint)[0], '/');
		//add suffix?
		if(!empty($suffix)) {
			$endpoint .= '/' . trim($suffix, '/');
		}
		//return
		return $endpoint;
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

	protected function callApi($endpoint, $method, array $query = []) {
		//build url
		$url = $this->kernel->url($endpoint, [ 'query' => $query ]);
		//make http call
		$result = $this->kernel->http($url, [
			'method' => $method ?: 'GET',
		]);
		//valid json response?
		if(!$result || !is_array($result) || !isset($result['data'])) {
			throw new \Exception('API endpoint not found');
		}
		//valid method?
		if($method && isset($result['data']['methods']) && $result['data']['methods']) {
			if(!in_array($method, $result['data']['methods'])) {
				throw new \Exception('API endpoint does not accept ' . $method . ' request method');
			}
		}
		//return
		return $result['data'];
	}

	protected function formJs() {
		//buffer
		ob_start();
?>
<script>
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
			if(type === 'error') {
				form.querySelector('.notice').scrollIntoView();
			} else {
				form.parentNode.scrollIntoView();
			}
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
</script>
<?php
		//return
		return trim(ob_get_clean());
	}

}