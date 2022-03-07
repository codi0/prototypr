<?php

namespace Codi0\Prototypr;

class Db extends \PDO {

	protected $queryLog = [];

	public function __construct($dsn = null, $username = null, $password = null, array $options = null) {
		//call parent
		parent::__construct($dsn, $username, $password, $options);
		//set default statement class
		$this->setStatementClass(get_class($this) . 'Statement');
	}

	public function getQueryLog() {
		return $this->queryLog;
	}

	public function setStatementClass($class) {
		$this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, array( $class, array( $this ) ));
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
		$this->queryLog[] = $statement;
		//call parent
		return parent::prepare($statement, $options);
	}

	public function query($query, $fetchMode = NULL, ...$fetchModeArgs) {
		//has params?
		if(is_array($fetchMode)) {
			$s = $this->prepare($query);
			return $s->execute($fetchMode);
		}
		//log query
		$this->queryLog[] = $query;
		//call parent
		return parent::query($query, $fetchMode);
	}

}

class DbStatement extends \PDOStatement {

	private $dbh;

	public function __construct($dbh) {
		$this->dbh = $dbh;
	}

}