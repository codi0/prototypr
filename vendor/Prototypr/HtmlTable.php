<?php

namespace Prototypr;

class HtmlTable {

	use ConstructTrait;

	protected $name = '';
	protected $attr = [];

	protected $columns = [];
	protected $data = [];
	protected $filters = [];

	protected $sortColumn = 'id';
	protected $sortAsc = true;
	protected $sortCallback;

	protected static $instances = [];

	public static function factory($name, array $opts = []) {
		//create instance?
		if(!isset(self::$instances[$name])) {
			//format opts?
			if($opts && !isset($opts['data'])) {
				$opts = [ 'data' => $opts ];
			}
			//set name
			$opts['name'] = $name;
			//create object
			self::$instances[$name] = new self($opts);
		}
		//return
		return self::$instances[$name];
	}

	public function __toString() {
		return $this->render();
	}

	public function name($name = null) {
		//set name?
		if($name) {
			//update prop
			$this->name = $name;
			//chain it
			return $this;
		}
		//return
		return $this->name;
	}

	public function attr($key, $val = null) {
		//set attr
		if(is_array($key)) {
			$attr = $key;
		} else {
			$attr = [ $key => $val ];
		}
		//update attr
		foreach($attr as $k => $v) {
			$this->attr[$k] = $v;
		}
		//chain it
		return $this;
	}

	public function hasColumns() {
		//guess columns?
		if(!$this->columns && $this->data) {
			//get keys
			$tmp = $this->data;
			$keys = array_keys(array_shift($tmp));
			//loop through keys
			foreach($keys as $k) {
				$this->columns[$k] = str_replace([ '-', '_' ], ' ', ucfirst($k));
			}
		}
		//return
		return !!$this->columns;
	}

	public function columns(array $columns) {
		//set columns
		$this->columns = $columns;
		//chain it
		return $this;
	}

	public function data(array $data) {
		//set data
		$this->data = $data;
		//chain it
		return $this;
	}

	public function addColumn($key, $title) {
		//guess columns
		$this->hasColumns();
		//add column
		$this->columns[$key] = $title;
		//chain it
		return $this;
	}

	public function addRow(array $row) {
		//add row
		$this->data[] = $row;
		//chain it
		return $this;
	}

	public function orderColumns(array $keys) {
		//set vars
		$newCols = [];
		//guess columns
		$this->hasColumns();
		//loop through keys
		foreach($keys as $k) {
			//column exists?
			if(isset($this->columns[$k])) {
				$newCols[$k] = $this->columns[$k];
				unset($this->columns[$k]);
			}
		}
		//add remaining columns
		foreach($this->columns as $k => $v) {
			$newCols[$k] = $v;
		}
		//update columns
		$this->columns = $newCols;
		//chain it
		return $this;
	}

	public function sortData($columnKey, $order) {
		//set sort props
		if(is_callable($columnKey)) {
			$this->sortCallback = $columnKey;
		} else {
			$this->sortColumn = $columnKey;
			$this->sortAsc = $order && strtolower($order) != 'desc';
		}
		//chain it
		return $this;
	}

	public function filterCell($callback) {
		//add callback
		$this->filters[] = $callback;
		//chain it
		return $this;
	}

	public function render() {
		//can render?
		if(!$this->hasColumns()) {
			return '<div class="table-not-found">No data available to render table</div>';
		}
		//sort data?
		if($this->sortCallback || $this->sortColumn) {
			//set vars
			$col = $this->sortColumn;
			$asc = $this->sortAsc;
			//custom sort
			uasort($this->data, $this->sortCallback ?: function($a, $b) use($col, $asc) {
				$l = $asc ? 1 : -1;
				$r = $asc ? -1 : 1;
				if(!isset($a[$col]) || $a[$col] == $b[$col]) {
					return 0;
				} else {
					return $a[$col] > $b[$col] ? $l : $r;
				}
			});
		}
		//reset data keys
		$this->data = array_values($this->data);
		//set id attr
		$this->attr('id', $this->name . '-table');
		//open table
		$html = '<table' . Html::formatAttr($this->attr). '>' . "\n";
		//open table head
		$html .= '<thead>' . "\n";
		//open columns
		$html .= '<tr>';
		//set columns
		foreach($this->columns as $col => $cell) {
			//filter cell value
			foreach($this->filters as $cb) {
				//execute filter
				$tmp = call_user_func($cb, $coll, $this->columns, true);
				//update value?
				if($tmp !== null) {
					$cell = $tmp;
				}
			}
			//add cell
			$html .= '<th key="' . $col . '">' . $cell . '</th>';
		}
		//close headers
		$html .= '</tr>' . "\n";
		//open table head
		$html .= '</thead>' . "\n";
		//open table body
		$html .= '<tbody>' . "\n";
		//loop through rows
		for($i=0; $i < count($this->data); $i++) {
			//get row
			$row = $this->data[$i];
			//open row
			$html .= '<tr>';
			//loop through columns
			foreach($this->columns as $col => $title) {
				//get cell value
				$cell = isset($row[$col]) ? $row[$col] : '';
				//filter cell value
				foreach($this->filters as $cb) {
					//execute filter
					$tmp = call_user_func($cb, $col, $row, false);
					//update value?
					if($tmp !== null) {
						$cell = $tmp;
					}
				}
				//add cell
				$html .= '<td>' . print_r($cell ?: '', true) . '</td>';
			}
			//close row
			$html .= '</tr>' . "\n";
		}
		//close table body
		$html .= '</tbody>' . "\n";
		//close table
		$html .= '</table>' . "\n";
		//return
		return $html;
	}

}