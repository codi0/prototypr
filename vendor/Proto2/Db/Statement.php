<?php

namespace Proto2\Db;

class Statement {

	protected $conn;
	protected $result;
	protected $rowsAffected = 0;

	public $queryString = '';
	public $params = [];

	public function __construct($conn, $queryString) {
		//set properties
		$this->conn = $conn;
		$this->queryString = $queryString;
	}

	public function rowCount() {
		return $this->rowsAffected;
	}

	public function execute(array $params=[]) {
		//execute query
		$res = $this->conn->query($this->queryString, $params);
		//cache result?
		if($res instanceof \mysqli_result) {
			$this->result = $res;
		} else if(isset($this->conn->result) && ($this->conn->result instanceof \mysqli_result)) {
			$this->result = $this->conn->result;
		} else {
			//failed
			return false;
		}
		//set rows affected
		if(isset($this->conn->rowsAffected)) {
			$this->rowsAffected = $this->conn->rowsAffected;
		} else if(isset($this->conn->rows_affected)) {
			$this->rowsAffected = $this->conn->rows_affected;
		}
		//reset pointer
		$this->result->data_seek(0);
		//success
		return true;
	}

	public function fetch($mode=null) {
		return $this->result->fetch_assoc();
	}

	public function fetchColumn($offset=0) {
		return $this->result->fetch_column($offset);
	}

	public function fetchAll($mode=null, $offset=0) {
		return $this->result->fetch_all(\MYSQLI_ASSOC);
	}

}