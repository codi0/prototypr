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

	public function errors($label='') {
		//set vars
		$errors = [];
		//format label
		$label = explode('.', $label ?: '');
		$label = ucfirst(str_replace('_', ' ', array_pop($label)));
		//loop through errors
		foreach($this->errors as $error) {
			$errors[] = trim(str_replace(':label', $label, $error));
		}
		//reset errors
		$this->errors = [];
		//return
		return $errors;
	}

	public function isValid($rules, $value) {
		//set vars
		$result = true;
		//format rules?
		if(!is_array($rules)) {
			$rules = array_map('trim', explode('|', $rules));
		}
		//is field not set?
		if($this->ruleRequired($value)) {
			$rules = in_array('required', $rules) ? [ 'required' ] : [];
		}
		//loop through rules
		foreach(array_unique($rules) as $rule) {
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
				$result = false;
			}
		}
		//return
		return $result;
	}

	public function filter($filters, $value) {
		//format filters?
		if(!is_array($filters)) {
			$filters = array_map('trim', explode('|', $filters));
		}
		//loop through filters
		foreach(array_unique($filters) as $filter) {
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
			$value = call_user_func($filter, $value, ...$args);
		}
		//return
		return $value;
	}

	protected function ruleRequired($value) {
		if($value === null || $value === '' || $value === 0 || $value === '0') {
			return ':label required';
		}
	}

	protected function ruleNowhitespace($value) {
		if(preg_match('/\s/', $value)) {
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
		if(!preg_match($pattern, $value)) {
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

	protected function ruleString($value) {
		//validation failed?
		if(!is_string($value)) {
			return ':label must be a string'; 
		}
	}

	protected function filterString($value) {
		return (string) ($value ?: '');
	}

	protected function ruleInt($value) {
		//validation failed?
		if((string) $value !== (string) intval($value)) {
			return ':label must be an integer'; 
		}
	}

	protected function filterInt($value) {
		return intval($value);
	}

	protected function ruleInteger($value) {
		return $this->ruleInt($value);
	}

	protected function filterInteger($value) {
		return $this->filterInt($value);
	}

	protected function ruleId($value) {
		if(!preg_match('/^[0-9]+$/', $value)) {
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
		if(!preg_match('/^[0-9]' . $r . '$/', $value)) {
			return ':label must be ' . ($length ? $length . ' digits ' : 'digits only');
		}
	}

	protected function filterDigits($value) {
		return preg_replace('/[^0-9]/', '', $value);
	}

	protected function ruleNumeric($value) {
		//validation failed?
		if(!is_numeric($value)) {
			return ':label must be a number'; 
		}
	}

	protected function filterNumeric($value) {
		return preg_replace('/[^\+\-\.0-9]/', '', $value);
	}

	protected function ruleAlphanumeric($value) {
		//validation failed?
		if(!preg_match('/^[a-z0-9]+$/i', $value)) {
			return ':label must only contain letters and numbers'; 
		}
	}

	protected function filterAlphanumeric($value) {
		return preg_replace('/[^a-z0-9]/i', '', $value);
	}

	protected function ruleUuid($value) {
		//validation failed?
		if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89AB][a-f0-9]{3}-[a-f0-9]{12}$/i', $value)) {
			return ':label must be a valid UUID'; 
		}
	}

	protected function filterUuid($value) {
		return preg_replace('/[^a-f0-9\-]/i', '', $value);
	}

	protected function ruleArray($value) {
		//validation failed?
		if($value !== (array) $value) {
			return ':label must be an array'; 
		}
	}

	protected function filterArray($value) {
		return (array) ($value ?: []);
	}

	protected function ruleObject($value) {
		//validation failed?
		if($value !== (object) $value) {
			return ':label must be an object'; 
		}
	}

	protected function filterObject($value) {
		return (object) $value;
	}

	protected function ruleBool($value) {
		//validation failed?
		if($value !== (bool) $value) {
			return ':label must be a boolean'; 
		}
	}

	protected function filterBool($value) {
		return !!$value;
	}

	protected function ruleBoolean($value) {
		return $this->ruleBool($value);
	}

	protected function filterBoolean($value) {
		return $this->filterBool($value);
	}

	protected function ruleNull($value) {
		//validation failed?
		if($value !== null) {
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
		}
		//format value
		$value = $value ?: '';
		//min length failed?
		if(strlen($value) < $min) {
			return ':label must be at least ' . $min . ' characters'; 
		}
		//max length failed?
		if(strlen($value) > $max) {
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
		}
		//format value
		$value = $value ?: '';
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
		}
		//min range failed?
		if($value < $min) {
			return ':label must be at least ' . $min;
		}
		//max range failed?
		if($value > $max) {
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
		if(!filter_var($value, FILTER_VALIDATE_EMAIL)) {
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
		if(!preg_match('/^' . $cc . '[0-9]{6,14}$/', $valFormatted)) {
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
		if($this->$method($value)) {
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
		if(!filter_var($value, FILTER_VALIDATE_URL)) {
			return ':label must be a valid URL'; 
		}
	}

	protected function filterUrl($value) {
		return filter_var($value, FILTER_SANITIZE_URL);
	}

	protected function ruleIp($value) {
		//validation failed?
		if(!filter_var($value, FILTER_VALIDATE_IP)) {
			return ':label must be a valid IP address'; 
		}
	}

	protected function filterIp($value) {
		return preg_replace('/[^\.0-9]/', '', $value);
	}

	protected function ruleDateFormat($value, $format='Y-m-d') {
		//check first part only?
		if($value && strpos($format, ' ') === false) {
			$value = explode(' ', $value)[0];
		}
		//convert to datetime
		$d = \DateTime::createFromFormat($format, $value);
		//format matches?
		if(!$d || $d->format($format) !== $value) {
			return ':label must be a valid ' . $format . ' date';
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
		if($value !== filter_var($value, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES)) {
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
		if($value !== $equals) {
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
		if($this->kernel->db->get_var("SELECT $field FROM $table WHERE $field = %s", $value)) {
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
		if($hash !== $value) {
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