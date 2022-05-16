<?php

namespace Prototypr;

class Validator {

	use ConstructTrait;

	protected $rules = [];
	protected $filters = [];
	protected $errors = [];

	protected function onConstruct(array $opts) {
		//get methods
		$methods = get_class_methods($this);
		//loop through methods
		foreach($methods as $method) {
			//is rule?
			if(strpos($method, 'rule') === 0) {
				$name = lcfirst(substr($method, 4));
				$this->rules[$name] = [ $this, $method ];
			}
			//is filter?
			if(strpos($method, 'filter') === 0) {
				$name = lcfirst(substr($method, 6));
				$this->filters[$name] = [ $this, $method ];
			}
		}
	}

	public function addRule($name, $fn) {
		$this->rules[$name] = $fn;
	}

	public function addFilter($name, $fn) {
		$this->filters[$name] = $fn;
	}

	public function errors($label='field') {
		//set vars
		$errors = [];
		//loop through errors
		foreach($this->errors as $error) {
			$errors[] = str_replace(':label', $label, $error);
		}
		//reset errors
		$this->errors = [];
		//return
		return $errors;
	}

	public function isValid($rule, $value) {
		//set vars
		$args = [];
		//has args?
		if(strpos($rule, '(') !== false) {
			list($rule, $args) = explode('(', $rule);
			$args = array_map('trim', explode(',', trim($args, ')')));
		}
		//rule exists?
		if(!isset($this->rules[$rule])) {
			throw new \Exception("Validation rule $rule does not exist");
		}
		//is callable?
		if(!is_callable($this->rules[$rule])) {
			throw new \Exception("Validation rule $rule is not a valid callable");
		}
		//execute callback
		$error = call_user_func($this->rules[$rule], $value, ...$args);
		//store error?
		if(!empty($error)) {
			$this->errors[] = $error;
		}
		//return
		return empty($error);
	}

	public function filter($filter, $value) {
		//set vars
		$args = [];
		//has args?
		if(strpos($filter, '(') !== false) {
			list($filter, $args) = explode('(', $filter);
			$args = array_map('trim', explode(',', trim(')', $args)));
		}
		//has filter?
		if(is_string($filter) && isset($this->filters[$filter])) {
			$filter = $this->filters[$filter];
		}
		//is callable?
		if(!is_callable($filter)) {
			throw new \Exception("Filter is not a valid callable");
		}
		//force to string?
		if(is_string($filter) && strpos($filter, 'str') === 0) {
			$value = is_string($value) ? $value : '';
		}
		//execute callback
		return call_user_func($filter, $value, ...$args);
	}

	protected function ruleRequired($value) {
		if(empty($value)) {
			return ':label is required';
		}
	}

	protected function ruleNowhitespace($value) {
		if($value && preg_match('/\s/', $value)) {
			return ':label must not contain whitespace';
		}
	}

	protected function filterNowhitespace($value) {
		return preg_replace('/s+/', '', $value);
	}

	protected function ruleRegex($value, $pattern='') {
		//has pattern?
		if(empty($pattern)) {
			throw new \Exception('Regex parameter required');
		}
		//set vars
		$pattern = '/' . preg_quote($pattern, '/') . '/';
		//validation failed?
		if($value && !preg_match($pattern, $value)) {
			return ':label must match regex pattern'; 
		}
	}

	protected function filterRegex($value, $pattern='') {
		//has pattern?
		if(empty($pattern)) {
			throw new \Exception('Regex parameter required');
		}
		//set vars
		$pattern = '/' . preg_quote($pattern, '/') . '/';
		//filter input
		return preg_replace($pattern, '', $value);
	}

	protected function ruleInt($value) {
		//validation failed?
		if($value && ((string) $value !== (string) intval($value))) {
			return ':label must be an integer'; 
		}
	}

	protected function filterInt($value) {
		return intval($value);
	}

	protected function ruleId($value) {
		if($value && !preg_match('/^[0-9]+$/', $value)) {
			return ':label must be a numeric ID';
		}
	}

	protected function filterId($value) {
		return preg_replace('/[^0-9]/', '', $value);
	}

	protected function ruleDigits($value, $length=null) {
		//set args
		$r = $length ? '{' . $length . '}' : '+';
		//error found?
		if($value && !preg_match('/^[0-9]' . $r . '$/', $value)) {
			return ':label must be ' . ($length ? $length . ' digits ' : 'digits only');
		}
	}

	protected function filterDigits($value) {
		return preg_replace('/[^0-9]/', '', $value);
	}

	protected function ruleNumeric($value) {
		//validation failed?
		if($value && !is_numeric($value)) {
			return ':label must be a number'; 
		}
	}

	protected function filterNumeric($value) {
		return preg_replace('/[^\+\-\.0-9]/', '', $value);
	}

	protected function ruleAlphanumeric($value) {
		//validation failed?
		if($value && !preg_match('/^[a-z0-9]+$/i', $value)) {
			return ':label must only contain letters and numbers'; 
		}
	}

	protected function filterAlphanumeric($value) {
		return preg_replace('/[^a-z0-9]/i', '', $value);
	}

	protected function ruleUuid($value) {
		//validation failed?
		if($value && !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89AB][a-f0-9]{3}-[a-f0-9]{12}$/i', $value)) {
			return ':label must be a valid UUID'; 
		}
	}

	protected function filterUuid($value) {
		return preg_replace('/[^a-f0-9\-]/i', '', $value);
	}

	protected function ruleBool($value) {
		//validation failed?
		if($value && ($value !== (bool) $value)) {
			return ':label must be a boolean'; 
		}
	}

	protected function filterBool($value) {
		return !!$value;
	}

	protected function ruleNull($value) {
		//validation failed?
		if($value && $value !== null) {
			return $this->addError(':label must be null'); 
		}
	}

	protected function filterNull($value) {
		return null;
	}

	protected function ruleLength($value, $min=0, $max=0) {
		//has min and max?
		if(!$min && !$max) {
			throw new \Exception('Min,Max length parameters required');
		}
		//set max as min?
		if(!$max && $min) {
			$max = $min;
			$min = 0;
		}
		//min length failed?
		if($value && strlen($value) < $min) {
			return ':label must be at least ' . $min . ' characters'; 
		}
		//max length failed?
		if($value && strlen($value) > $max) {
			return ':label must be no more than ' . $max . ' characters'; 
		}
	}

	protected function filterLength($value, $min=0, $max=0) {
		//has min and max?
		if(!$min && !$max) {
			throw new \Exception('Min,Max length parameters required');
		}
		//set max as min?
		if(!$max && $min) {
			$max = $min;
			$min = 0;
		}
		//pad to min length?
		if(strlen($value) < $min) {
			$value = str_pad($value, $min);
		}
		//cut to max length?
		if(strlen($value) > $max) {
			$value = substr($value, 0, $max);
		}
		//return
		return $value;
	}

	protected function ruleRange($value, $min=0, $max=0) {
		//has min and max?
		if(!$min && !$max) {
			throw new \Exception('Min,Max numeric range parameters required');
		}
		//set max as min?
		if(!$max && $min) {
			$max = $min;
			$min = 0;
		}
		//min range failed?
		if($value && $value < $min) {
			return ':label must be at least ' . $min;
		}
		//max range failed?
		if($value && $value > $max) {
			return ':label must be no more than ' . $min;
		}
	}

	protected function filterRange($value, $min=0, $max=0) {
		//has min and max?
		if(!$min && !$max) {
			throw new \Exception('Min,Max numeric range parameters required');
		}
		//set max as min?
		if(!$max && $min) {
			$max = $min;
			$min = 0;
		}
		//set to min?
		if($value < $min) {
			$value = $min;
		}
		//set to max?
		if($value > $max) {
			$value = $max;
		}
		//return
		return $value;
	}

	protected function ruleEmail($value) {
		if($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
			return ':label must be a valid email';
		}
	}

	protected function filterEmail($value) {
		return filter_var($value, FILTER_SANITIZE_EMAIL);
	}

	protected function rulePhone($value, $cc='') {
		//remove optional chars
		$valFormatted = str_replace('-', '', $value);
		$valFormatted = preg_replace('/\s+/', '', $valFormatted);
		//use cc?
		if(strpos($cc, '+') === 0) {
			$cc = str_replace('+', '\+', $cc);
		} else if($cc === 'true' || $cc === 'cc' || $cc === true) {
			$cc = '\\+';
		} else {
			$cc = '';
		}
		//valid format?
		if($value && !preg_match('/^' . $cc . '[0-9]{6,14}$/', $valFormatted)) {
			return ':label must be a valid phone number'; 
		}
	}

	protected function filterPhone($value, $cc='') {
		//remove optional characters
		$value = str_replace('-', '', $value);
		$value = preg_replace('/\s+/', '', $value);
		//has country code?
		if(strpos($value, '+') !== 0) {
			//guess code?
			if($cc && $cc !== 'false') {
				//is UK?
				if(strpos($value, '0') === 0 && strlen($value) == 11) {
					$cc = '+44';
				}
			}
			//add code?
			if(strpos($cc, '+') === 0) {
				$value = $cc . preg_replace('/^0+/', '', $value);
			}
		}	
		//return
		return $value;
	}

	protected function rulePhoneOrEmail($value) {
		//set vars
		$method = 'rulePhone';
		//looks like email?
		if(strpos($value, '@') !== false || !preg_match('/[0-9]/', $value)) {
			$method = 'ruleEmail';
		}
		//validation failed?
		if($value && $this->$method($value)) {
			return ':label must be a valid email or phone number';
		}
	}

	protected function filterPhoneOrEmail($value) {
		//set vars
		$method = 'rulePhone';
		//looks like email?
		if(strpos($value, '@') !== false || !preg_match('/[0-9]/', $value)) {
			$method = 'ruleEmail';
		}
		//return
		return $this->$method($value);
	}

	protected function ruleUrl($value) {
		//validation failed?
		if($value && !filter_var($value, FILTER_VALIDATE_URL)) {
			return ':label must be a valid URL'; 
		}
	}

	protected function filterUrl($value) {
		return filter_var($value, FILTER_SANITIZE_URL);
	}

	protected function ruleIp($value) {
		//validation failed?
		if($value && !filter_var($value, FILTER_VALIDATE_IP)) {
			return ':label must be a valid IP address'; 
		}
	}

	protected function filterIp($value) {
		return preg_replace('/[^\.0-9]/', '', $value);
	}

	protected function ruleDateFormat($value, $format='Y-m-d') {
		//has value?
		if($value) {
			//check first part only?
			if(strpos($format, ' ') === false) {
				$value = explode(' ', $value)[0];
			}
			//convert to datetime
			$d = \DateTime::createFromFormat($format, $value);
			//format matches?
			if(!$d || $d->format($format) !== $value) {
				return ':label must be a valid ' . $format . ' date';
			}
		}
	}

	protected function filterDateFormat($value, $format='Y-m-d') {
		//to timestamp?
		if(!is_numeric($value)) {
			$value = strtotime($value);
		}
		//create date time
		$d = new DateTime();
		//set timestamp
		$d->setTimestamp($value);
		//return
		return $d->format($format);
	}

	protected function ruleXss($value) {
		//decode input
		$value = rawurldecode(rawurldecode($value));
		//run unsafe check
		$unsafe = preg_replace('/\s+/', '', $value); 
		$unsafe = preg_match('/(onclick|onload|onerror|onmouse|onkey)|(script|alert|confirm)[\:\>\(]/iS', $unsafe);
		//validation failed?
		if($value && $value !== filter_var($value, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES)) {
			return ':label contains unsafe characters'; 
		}
	}

	protected function filterXss($value) {
		//decode input
		$value = rawurldecode(rawurldecode($value));
		//run unsafe check
		$unsafe = preg_replace('/\s+/', '', $value); 
		$unsafe = preg_match('/(onclick|onload|onerror|onmouse|onkey)|(script|alert|confirm)[\:\>\(]/iS', $unsafe);
		//filter input
		return $unsafe ? '' : filter_var($value, FILTER_SANITIZE_STRING);
	}

	protected function ruleEquals($value, $compare='') {
		//has compare?
		if(!$compare) {
			throw new \Exception('Equals field parameter required');
		}
		//is global?
		if(strpos($compare, '.') !== false) {
			//parse global
			list($global, $attr) = explode('.', $compare, 2);
			//format global identifier
			$global = '_' . strtoupper(trim($global, '_'));
			//global attr found?
			if(isset($GLOBALS[$global]) && isset($GLOBALS[$global][$attr])) {
				$equals = $GLOBALS[$global][$attr];
			} else {
				$equals = '';
			}
		} else {
			//use raw param
			$equals = $attr = $compare;
		}
		//validation failed?
		if($value && $value !== $equals) {
			return ':label must be equal to ' . $attr; 
		}
	}

	protected function ruleUnique($value, $field='') {
		//has db service?
		if(!$this->kernel || !isset($this->kernel->db)) {
			throw new \Exception('Db service not found');
		}
		//has field?
		if(empty($field)) {
			throw new \Exception('Database field parameter required');
		}
		//has table?
		if(strpos($field, '.') === false) {
			throw new \Exception('Database field parameter should include table name');
		}
		//parse table name
		list($table, $field) = explode('.', $field, 2);
		//does value exist?
		if($value && $this->kernel->db->get_var("SELECT $field FROM $table WHERE $field = %s", $value)) {
			return ':label already exists'; 
		}
	}

	protected function ruleCaptcha($value) {
		//has captcha service?
		if(!$this->kernel || !isset($this->kernel->captcha)) {
			throw new \Exception('Captcha service not found');
		}
		//validation failed?
		if(!$this->kernel->captcha->isValid($value)) {
			return ':label must match captcha'; 
		}
	}

	protected function ruleHashPwd($value) {
		//has crypt service?
		if(!$this->kernel || !isset($this->kernel->crypt)) {
			throw new \Exception('Crypt service not found');
		}
		//build hash
		$hash = $this->crypt->hashPwd($value);
		//validation failed?
		if($value && $hash !== $value) {
			return ':label must be a hash';
		}
	}

	protected function filterHashPwd($value) {
		//has crypt service?
		if(!$this->kernel || !isset($this->kernel->crypt)) {
			throw new \Exception('Crypt service not found');
		}
		//build hash
		return $this->crypt->hashPwd($value);
	}

}