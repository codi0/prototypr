<?php

namespace Proto2\Html;

class Form {

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

	protected $onLoad = [];
	protected $onSave = [];
	protected $onSuccess = [];
	protected $onError = [];

	protected $input;
	protected $htmlField;
	protected $validator;

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
		//set default ID?
		if($this->name && !isset($this->attr['id'])) {
			$this->attr['id'] = $this->name;
		}
		//set default method?
		if(!isset($this->attr['method'])) {
			$this->attr['method'] = 'post';
		}
		//make lowercase
		$this->attr['method'] = strtolower($this->attr['method']);
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

	public function message($message, $success=null) {
		//set message?
		if($this->message !== null) {
			$this->message = (string) strip_tags($message);
			$this->messageSuccess = (bool) (is_null($success) ? $this->messageSuccess : $success);
			return $this;
		}
		//get message
		return $this->message;
	}

	public function fieldParam($name, $key=null, $val=null) {
		//field exists?
		if(!isset($this->fields[$name])) {
			throw new \Exception("Field $name does not exist");
		}
		//set field param?
		if($val !== null) {
			$this->fields[$name][$key] = $val;
			return $this;
		}
		//get all params?
		if($key === null) {
			return $this->fields[$name];
		}
		//get one param
		return isset($this->fields[$name][$key]) ? $this->fields[$name][$key] : null;
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
		if(isset($config['type']) && in_array($config['type'], [ 'hidden', 'button', 'submit' ])) {
			if(!isset($config['label'])) {
				$config['label'] = '';
			}
		}
		//set required rule?
		if(isset($config['required']) && $config['required']) {
			//format rules
			$config['rules'] = isset($config['rules']) ? $config['rules'] : [];
			$config['rules'] = (is_array($config['rules']) || !$config['rules']) ? $config['rules'] : explode('|', $config['rules']);
			//add to rules?
			if(!in_array('required', $config['rules'])) {
				$config['rules'][] = 'required';
			}
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

	public function html($html, array $config=[]) {
		//generate name
		$name = mt_rand(10000, 100000);
		//set config
		$config['label'] = '';
		$config['html'] = $html;
		//return
		return $this->input($name, $config);
	}

	public function onLoad($cb) {
		//set callback
		$this->onLoad[] = $cb;
		//chain it
		return $this;
	}

	public function onSave($cb) {
		//set callback
		$this->onSave[] = $cb;
		//chain it
		return $this;
	}

	public function onSuccess($cb) {
		//set callback
		$this->onSuccess[] = $cb;
		//chain it
		return $this;
	}

	public function onError($cb) {
		//set callback
		$this->onError[] = $cb;
		//chain it
		return $this;
	}

	public function isValid() {
		//already run?
		if($this->isValid !== null) {
			return $this->isValid;
		}
		//form data
		$fields = [];
		$values = [];
		$files = $this->input->files();
		$method = ($this->attr['method'] === 'get') ? 'get' : 'post';
		$global = $GLOBALS['_' . strtoupper($method)];
		$formId = $this->input->request('id');
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
		//reset errors
		$this->errors = [];
		//loop through fields
		foreach($this->fields as $name => $opts) {
			//set vars
			$rules = [];
			$filters = [];
			$override = isset($opts['override']) && $opts['override'];
			//set file?
			if(isset($opts['type']) && $opts['type'] === 'file') {
				if(isset($files[$name]) && $files[$name]) {
					$opts['file'] = $files[$name];
				}
			}
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
			if(isset($opts['file']) && $opts['file']) {
				//new upload?
				if($opts['file']->getClientFilename()) {
					//format file path
					$values[$name] = preg_replace_callback('/%([^%]*)%/', function($m) use($fields, $opts) {
						$val = '';
						if($m[1] == 'ext') {
							$val = pathinfo($opts['file']->getClientFilename(), PATHINFO_EXTENSION);
						} else if(isset($fields[$m[1]])) {
							$val = $this->input->request($m[1]);
						}
						return $val;
					}, isset($opts['path']) ? $opts['path'] : '');
				} else {
					//get existing value
					$values[$name] = isset($opts['value']) ? $opts['value'] : '';
				}
			} else {
				//get input value
				$values[$name] = $this->input->request($name);
			}
			//process filters
			$values[$name] = $this->validator->filter($filters, $values[$name]);
			//process rules
			if($this->validator->isValid($rules, $values[$name])) {
				//move file?
				if(isset($opts['file']) && $opts['file']) {
					//new upload?
					if($opts['file']->getClientFilename()) {
						try {
							$opts['file']->moveTo($values[$name]);
						} catch(\Exception $e) {
							$this->validator->addError(':label file upload failed');
						}
					}
				}
			}
			//process errors
			foreach($this->validator->errors($name) as $error) {
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
			foreach($this->onSuccess as $cb) {
				//set context
				$cb = \Closure::bind($cb, $this, $this);
				//execute callback
				$res = $cb($this->values);
				//still valid?
				if($res === false || $this->errors) {
					$this->isValid = false;
				}
			}
		} else {
			//error callback?
			foreach($this->onError as $cb) {
				//set context
				$cb = \Closure::bind($cb, $this, $this);
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
		//onload callback?
		foreach($this->onLoad as $cb) {
			//set context
			$cb = \Closure::bind($cb, $this, $this);
			//run callback
			$cb();
		}
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
		$fields = '';
		$formAttr = [];
		$modelData = $this->getModelData();
		//reset props
		$this->fieldset = '';
		//set default message?
		if(!$this->message && $this->isValid) {
			$this->message = 'Form successfully submitted';
		}
		//create fields html
		foreach($this->fields as $name => $opts) {
			//set vars
			$field = '';
			//format opts
			$opts = array_merge([
				'name' => $name,
				'id' => $this->attr['id'] . '-' . $name,
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
			//is multi-part form?
			if(!isset($this->attr['enctype']) && $opts['type'] == 'file') {
				$this->attr['enctype'] = 'multipart/form-data';
			}
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
				$classes .= ($opts['type'] !== $name) ? ' ' . trim($name, '_') : '';
				$classes .= !$opts['label'] ? ' no-label' : '';
				//add html
				$field .= '<div class="' . preg_replace('/\_|\./', '-', $classes) . '">' . "\n";
			}
			//add field label?
			if(!empty($opts['label'])) {
				$field .= '<label for="' . $opts['id'] . '">' . $opts['label'] . ($opts['required'] ? '<span class="required">*</span>' : '') . '</label>' . "\n";
			}
			//render field html
			if(isset($opts['html']) && $opts['html']) {
				$field .= (is_callable($opts['html']) ? call_user_func($opts['html'], $opts) : $opts['html']) . "\n";
			} else {
				$method = $opts['type'];
				$attr = $this->formatFieldAttr($opts);
				$field .= $this->htmlField->$method($opts['name'], $opts['value'], $attr) . "\n";
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
					$fields .= '</fieldset>' . "\n";
				}
				//open fieldset?
				if($opts['fieldset']) {
					$fName = explode('.', $name)[0];
					$fAttr = array_merge([ 'data-name' => $fName ], $opts['fieldset_attr']);
					$fields .= '<fieldset' . Field::formatAttr($fAttr) . '>' . "\n";
					$fields .= '<legend>' . str_replace('_', ' ', ucfirst($opts['fieldset'])) . '</legend>' . "\n";
				}
				//cache fieldset
				$this->fieldset = $opts['fieldset'];
			}
			//add to html
			$fields .= $field;
		}
		//create form html
		$html .= '<div id="' . $this->attr['id'] . '-wrap" class="form-wrap">' . "\n";
		//add before form
		$html .= implode("\n", $this->html['before']);
		//open form
		$html .= '<form' . Field::formatAttr($this->attr) . '>' . "\n";
		//success message?
		if($this->message) {
			$html .= '<div class="notice ' . ($this->messageSuccess ? 'success' : 'error') . '">' . $this->message . '</div>' . "\n";
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
		//add fields
		$html .= $fields;
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
			$html .= '</script>' . "\n";
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
		$blacklist = [ 'name', 'value', 'label', 'error', 'validate', 'rules', 'filter', 'before', 'after', 'wrap', 'override', 'fieldset', 'fieldset_attr', 'file', 'path' ];
		//loop through attributes
		foreach($attr as $key => $val) {
			//is error?
			if($val && $key === 'error') {
				if(isset($attr['class']) && $attr['class']) {
					$attr['class'] .= ' has-error';
				} else {
					$attr['class'] = 'has-error';
				}
			}
			//in blacklist?
			if(in_array($key, $blacklist)) {
				//skip blacklist?
				if($key === 'wrap' && $val && is_string($val)) {
					continue;
				}
				//remove key
				unset($attr[$key]);
			}
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
		foreach($this->onSave as $cb) {
			//execute callback
			$tmp = $cb($values, $errors);
			//update values?
			if(is_array($tmp)) {
				$values = $tmp;
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