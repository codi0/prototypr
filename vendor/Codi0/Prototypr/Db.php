<?php

namespace Codi0\Prototypr;

class Db extends \PDO {

	protected $log = [];

	public function getLog() {
		return $this->log;
	}

	public function loadSchema($schema) {
		//load file?
		if(strpos($schema, ' ') === false) {
			$schema = file_get_contents($schema);
		}
		//loop through queries
		foreach(explode(';', $schema) as $query) {
			//trim query
			$query = trim($query);
			//execute query?
			if(!empty($query)) {
				$this->query($query);
			}
		}
	}

	public function prepare($statement, $options = NULL) {
		//log query
		$this->log[] = $statement;
		//call parent
		return parent::prepare($statement, $options);
	}

	public function query($query, $fetchMode = NULL) {
		//has params?
		if(is_array($fetchMode)) {
			$s = $this->prepare($query);
			return $s->execute($fetchMode);
		}
		//log query
		$this->log[] = $query;
		//call parent
		return parent::query($query, $fetchMode);
	}

}