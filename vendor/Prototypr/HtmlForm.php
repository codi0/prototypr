<?php

namespace Prototypr;

class HtmlForm {

	use ConstructTrait;

	protected $name = '';
	protected $fieldset = '';
	protected $attr = [];
	protected $fields = [];
	protected $values = [];
	protected $html = [ 'before' => [], 'after' => [] ];

	protected $model = [];
	protected $errors = [];

	protected $message = '';
	protected $messageSuccess = true;

	protected $isValid;
	protected $autosave = true;

	protected $onSave;
	protected $onSuccess;
	protected $onError;

	private static $instances = [];

	public static function factory($name, $method='post', $action='') {
		//is cached?
		if(!isset(self::$instances[$name])) {
			//set vars
			$opts = [];
			//method is opts?
			if(is_array($method)) {
				$opts = $method;
				$method = 'post';
			}
			//set name
			$opts['name'] = $name;
			//set attr
			$opts['attr'] = array_merge([
				'name' => $name,
				'id' => $name . '-form',
				'action' => $action,
				'method' => strtolower($method),
			], isset($opts['attr']) ? $opts['attr'] : []);
			//create instance
			self::$instances[$name] = new static($opts);
		}
		//return
		return self::$instances[$name];
	}

	protected function onConstruct(array $opts) {
		//set default method?
		if(!isset($this->attr['method'])) {
			$this->attr['method'] = 'post';
		}
		//set default message?
		if(!$this->message && $this->attr['method'] === 'post') {
			$this->message = 'Form successfully saved';
		}
	}

	public function __toString() {
		return $this->render();
	}

	public function __call($method, array $args) {
		//set args
		$name = isset($args[0]) ? $args[0] : '';
		$config = isset($args[1]) ? $args[1] : [];
		//set config type
		$config['type'] = $method;
		//return
		return $this->input($name, $config);
	}

	public function name($name=null) {
		//set name?
		if(!empty($name)) {
			$this->name = $name;
			$this->attr['name'] = $name;
			$this->attr['id'] = $name . '-form';
			return $this;
		}
		//return
		return $this->name;
	}

	public function model($model=null) {
		//set model?
		if(!empty($model)) {
			$this->model = $model;
			return $this;
		}
		//return
		return $this->model;
	}

	public function attr($key=null, $val=null) {
		//get all?
		if($key === null) {
			return $this->attr;
		}
		//set all?
		if(is_array($key)) {
			//replace?
			if($val === true) {
				$this->attr = $key;
			} else{
				$this->attr = array_merge($this->attr, $key);
			}
			//chain it
			return $this;
		}
		//set one?
		if($val !== null) {
			//set property
			$this->attr[$key] = $val;
			//chain it
			return $this;
		}
		//get one
		return isset($this->attr[$key]) ? $this->attr[$key] : null;
	}

	public function message($message=null, $success=true) {
		//get message?
		if($message !== null) {
			//set property
			$this->message = $message ?: '';
			$this->messageSuccess = (bool) $success;
			//chain it
			return $this;
		}
		//return
		return $this->message;
	}

	public function before($html, $after=false) {
		//set vars
		$position = $after ? 'after' : 'before';
		//save html?
		if($html = trim($html)) {
			$this->html[$position][] = $html . "\n";
		}
		//chain it
		return $this;
	}

	public function after($html) {
		return $this->before($html, true);
	}

	public function input($name, array $config=[]) {
		//remove label?
		if(isset($config['type']) && in_array($config['type'], [ 'hidden', 'submit' ])) {
			$config['label'] = '';
		}
		//add field
		$this->fields[$name] = $config;
		//chain it
		return $this;
	}

	public function captcha($label, array $config=[]) {
		//set config
		$config['type'] = 'captcha';
		$config['label'] = $label;
		$config['validate'] = 'captcha';
		//return
		return $this->input('captcha', $config);
	}

	public function submit($value, array $config=[]) {
		//set config
		$config['type'] = 'submit';
		$config['value'] = $value;
		//return
		return $this->input('submit', $config);
	}

	public function html($html, array $config=[]) {
		//generate name
		$name = mt_rand(10000, 100000);
		//set config
		$config['label'] = '';
		$config['html'] = $html;
		//return
		return $this->input($name, $config);
	}

	public function isValid() {
		//already run?
		if($this->isValid !== null) {
			return $this->isValid;
		}
		//form data
		$fields = [];
		$values = [];
		$method = ($this->attr['method'] === 'get') ? 'get' : 'post';
		$global = $GLOBALS['_' . strtoupper($method)];
		$formId = $this->kernel->input('id');
		//model data
		$modelId = $this->getModelId();
		$modelMeta = $this->getModelMeta();
		//method matched?
		if($method !== strtolower($_SERVER['REQUEST_METHOD'])) {
			return null;
		}
		//ID matched?
		if($modelId && $modelId != $formId) {
			return null;
		}
		//check fields match input
		foreach($this->fields as $name => $opts) {
			//is submit field?
			if(isset($opts['type']) && $opts['type'] === 'submit') {
				continue;
			}
			//does value exist?
			if(!isset($global[$name]) && !is_int($name)) {
				return null;
			}
			//add field
			$fields[$name] = $opts;
		}
		//reset errors
		$this->errors = [];
		//loop through fields
		foreach($fields as $name => $opts) {
			//is submit field?
			if(isset($opts['type']) && $opts['type'] === 'submit') {
				continue;
			}
			//set vars
			$rules = [];
			$filters = [];
			$override = isset($opts['override']) && $opts['override'];
			//setup validators
			foreach([ 'filters', 'rules' ] as $k) {
				//add model rules?
				if(!$override && isset($modelMeta[$name]) && $modelMeta[$name][$k]) {
					$tmp = is_array($modelMeta[$name][$k]) ? $modelMeta[$name][$k] : explode('|', $modelMeta[$name][$k]);
					$$k = array_merge($$k, $tmp);
				}
				//add form rules?
				if(isset($opts[$k]) && $opts[$k]) {
					$tmp = is_array($opts[$k]) ? $opts[$k] : explode('|', $opts[$k]);
					$$k = array_merge($$k, $tmp);
				}
			}
			//get value
			$values[$name] = $this->kernel->input($name);
			//process filters
			$values[$name] = $this->kernel->validator->filter($filters, $values[$name]);
			//process rules
			$this->kernel->validator->isValid($rules, $values[$name]);
			//process errors
			foreach($this->kernel->validator->errors($name) as $error) {
				//create array?
				if(!isset($this->errors[$name])) {
					$this->errors[$name] = [];
				}
				//add error
				$this->errors[$name][] = $error;
			}
			//skip empty?
			if($values[$name] === '' && in_array('skipEmpty', $rules)) {
				$values[$name] = null;
			}
		}
		//set vars
		$res = '';
		$id = $this->getModelId();
		//cache values
		$this->values = $values;
		$this->isValid = empty($this->errors);
		//save model?
		if($this->isValid && $this->model && $this->autosave) {
			//get result
			$id = $this->saveModelData($this->values, $this->errors);
			//save failed?
			if($this->errors || $id === false) {
				$res = false;
				$this->isValid = false;
			}
		}
		//successful submit?
		if($this->isValid) {
			//success callback?
			if($cb = $this->onSuccess) {
				//execute callback
				$res = $cb($this->values, $this->errors, $this->message);
				//still valid?
				if($res === false || $this->errors) {
					$this->isValid = false;
				}
			}
			//dispatch event?
			if($this->isValid) {
				$this->kernel->event('form.success', $this);
			}
		} else {
			//error callback?
			if($cb = $this->onError) {
				//execute callback
				$res = $cb($this->errors);
				//update errors?
				if(is_array($res)) {
					$this->errors = $res;
				}
			}
		}
		//redirect user?
		if($this->isValid && $res && is_string($res)) {
			//format url
			$url = str_replace([ '{id}', urlencode('{id}') ], $id, $res);
			//headers sent?
			if(headers_sent()) {
				echo '<meta http-equiv="refresh" content="0;url=' . $url . '">';
			} else {
				header('Location: ' . $url);
			}
			//stop
			exit();
		}
		//return
		return $this->isValid;
	}

	public function errors($field=null) {
		//one field?
		if($field) {
			return isset($this->errors[$field]) ? $this->errors[$field] : null;
		}
		//all fields
		return $this->errors;
	}

	public function values($field=null) {
		//one field?
		if($field) {
			return isset($this->values[$field]) ? $this->values[$field] : null;
		}
		//all fields
		return $this->values;
	}

	public function init() {
		//validate
		$this->isValid();
		//chain it
		return $this;
	}

	public function render() {
		//init
		$this->init();
		//set vars
		$html = '';
		$formAttr = [];
		$modelData = $this->getModelData();
		//reset props
		$this->fieldset = '';
		//open wrapper
		$html .= '<div id="' . $this->attr['id'] . '-wrap" class="form-wrap">' . "\n";
		//add before form
		$html .= implode("\n", $this->html['before']);
		//open form
		$html .= '<form' . Html::formatAttr($this->attr) . '>' . "\n";
		//success message?
		if($this->message) {
			if($this->isValid || (is_null($this->isValid) && $this->kernel->input('GET.success') == 'true')) {
				$html .= '<div class="notice ' . ($this->messageSuccess ? 'success' : 'error') . '">' . $this->message . '</div>' . "\n";
			}
		}
		//error summary?
		if($this->errors && count($this->fields) > 10) {
			$html .= '<div class="notice error">Please review the errors below to continue:</div>' . "\n";
		}
		//show non field errors?
		foreach($this->errors as $key => $val) {
			//is field error?
			if(isset($this->fields[$key])) {
				continue;
			}
			//loop through errors
			foreach((array) $val as $v) {
				$html .= '<div class="error">' . $v . '</div>' . "\n";
			}
		}
		//add ID field?
		if($modelData && isset($modelData['id']) && $modelData['id']) {
			$html .= '<input type="hidden" name="id" value="' . $modelData['id'] . '">' . "\n";
		}
		//create fields
		foreach($this->fields as $name => $opts) {
			//set vars
			$field = '';
			//format opts
			$opts = array_merge([
				'name' => $name,
				'type' => 'text',
				'value' => null,
				'required' => false,
				'placeholder' => '',
				'label' => str_replace('_', ' ', ucfirst($name)),
				'error' => isset($this->errors[$name]) ? $this->errors[$name] : [],
				'wrap' => true,
				'fieldset' => '',
				'fieldset_attr' => [],
			], $opts);
			//update value?
			if(isset($this->values[$name])) {
				//use input
				$opts['value'] = $this->values[$name];
			} elseif($modelData && $opts['value'] === null) {
				//use data source
				$tmp = isset($modelData[$name]) ? $modelData[$name] : '';
				//set value?
				if($tmp || $tmp === 0 || $tmp === '0') {
					$opts['value'] = (string) $tmp;
				}
			}
			//convert name?
			if(strpos($opts['name'], '.') !== false) {
				//tmp name
				$tmpName = '';
				//loop through name parts
				foreach(explode('.', $opts['name']) as $part) {
					if($tmpName) {
						$tmpName .= '[' . $part . ']';
					} else {
						$tmpName .= $part;
					}
				}
				//update name
				$opts['name'] = $tmpName;
			}
			//add html before?
			if(isset($opts['before']) && $opts['before']) {
				$field .= trim($opts['before']) . "\n";
			}
			//add field wrap open?
			if($opts['wrap'] && $opts['type'] !== 'hidden') {
				//format classes
				$classes  = 'field ' . $opts['type'];
				$classes .= ($opts['type'] !== $name) ? ' ' . $name : '';
				$classes .= $opts['error'] ? ' has-error' : '';
				$classes .= !$opts['label'] ? ' no-label' : '';
				//add html
				$field .= '<div class="' . preg_replace('/\_|\./', '-', $classes) . '">' . "\n";
			}
			//add field label?
			if(!empty($opts['label'])) {
				$field .= '<label for="' . $opts['name'] . '">' . $opts['label'] . ($opts['required'] ? '<span class="required">*</span>' : '') . '</label>' . "\n";
			}
			//render field html
			if(isset($opts['html']) && $opts['html']) {
				$field .= (is_callable($opts['html']) ? call_user_func($opts['html'], $opts) : $opts['html']) . "\n";
			} else {
				$method = $opts['type'];
				$attr = $this->formatFieldAttr($opts);
				$field .= $this->kernel->html->$method($opts['name'], $opts['value'], $attr) . "\n";
			}
			//add field errors?
			foreach((array) $opts['error'] as $error) {
				$field .= '<div class="error">' . $error . '</div>' . "\n";
			}
			//add field wrap close?
			if($opts['wrap'] && $opts['type'] !== 'hidden') {
				$field .= '</div>' . "\n";
			}
			//add html after?
			if(isset($opts['after']) && $opts['after']) {
				$field .= trim($opts['after']) . "\n";
			}
			//setup fieldset?
			if($opts['fieldset'] != $this->fieldset) {
				//close fieldset?
				if($this->fieldset) {
					$html .= '</fieldset>' . "\n";
				}
				//open fieldset?
				if($opts['fieldset']) {
					$fName = explode('.', $name)[0];
					$fAttr = array_merge([ 'data-name' => $fName ], $opts['fieldset_attr']);
					$html .= '<fieldset' . Html::formatAttr($fAttr) . '>' . "\n";
					$html .= '<legend>' . str_replace('_', ' ', ucfirst($opts['fieldset'])) . '</legend>' . "\n";
				}
				//cache fieldset
				$this->fieldset = $opts['fieldset'];
			}
			//add to html
			$html .= $field;
		}
		//close fieldset?
		if($this->fieldset) {
			$html .= '</fieldset>' . "\n";
		}
		//close form
		$html .= '</form>' . "\n";
		//go to form?
		if($this->errors && $this->attr['method'] === 'post') {
			$html .= '<script>' . "\n";
			$html .= 'document.getElementById("' . $this->name . '-form").scrollIntoView();' . "\n";
			$html .- '</script>' . "\n";
		}
		//add after table
		$html .= implode("\n", $this->html['after']);
		//close table wrap
		$html .= '</div>' . "\n";
		//return
		return $html;
	}

	protected function formatFieldAttr(array $attr) {
		//blacklist keys
		$blacklist = [ 'name', 'value', 'label', 'error', 'validate', 'filter', 'before', 'after', 'wrap', 'override', 'fieldset', 'fieldset_attr' ];
		//loop through attributes
		foreach($attr as $key => $val) {
			//in blacklist?
			if(!in_array($key, $blacklist)) {
				continue;
			}
			//skip blacklist?
			if($key === 'wrap' && $val && is_string($val)) {
				continue;
			}
			//remove key
			unset($attr[$key]);
		}
		//return
		return $attr;
	}

	protected function getModelId() {
		//is object?
		if(is_object($this->model)) {
			return $this->model->id;
		}
		//return in array
		return isset($this->model['id']) ? $this->model['id'] : '';
	}

	protected function getModelMeta() {
		//set vars
		$modelMeta = [];
		//has model object?
		if(is_object($this->model)) {
			$class = get_class($this->model);
			$modelMeta = $class::__meta('props');
		}
		//return
		return $modelMeta;
	}

	protected function getModelData() {
		//set vars
		$modelData = $this->model;
		//has model object?
		if(is_object($this->model)) {
			$modelData = $this->model->toArray();
		}
		//return
		return $modelData;
	}

	protected function saveModelData($values, &$errors) {
		//set vars
		$id = null;
		//save callback?
		if($cb = $this->onSave) {
			//execute callback
			$values = $cb($values, $errors);
			//update values?
			if(!is_array($values)) {
				throw new \Exception("onSave callback must return an array");
			}
		}
		//update model?
		if($values && is_object($this->model)) {
			//attempt save
			$id = $this->model->set($values)->save();
			//get errors
			$errors = $this->model->errors();
		}
		//return
		return $id;
	}

}