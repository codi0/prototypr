<?php

namespace Proto2\Html;

class Table {

	protected $name = '';
	protected $attr = [];
	protected $columns = [];
	protected $data = [];

	protected $filters = [];
	protected $html = [ 'before' => [], 'after' => [] ];
	protected $notFoundMsg = 'No {name} records found';

	protected $sortColumn = 'id';
	protected $sortAsc = true;
	protected $sortCallback;

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
	}

	public function __toString() {
		return $this->render();
	}

	public function name($name=null) {
		//set name?
		if(!empty($name)) {
			$this->name = $name;
			return $this;
		}
		//return
		return $this->name;
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

	public function notFoundMsg($msg=null) {
		//set message?
		if(!empty($msg)) {
			$this->notFoundMsg = $msg;
			return $this;
		}
		//return
		return $this->notFoundMsg;
	}

	public function getColumns() {
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
		return $this->columns;
	}

	public function setColumns(array $columns, $replace=true) {
		//reset?
		if($replace) {
			$this->columns = [];
		} else {
			$this->getColumns();		
		}
		//add columns
		foreach($columns as $key => $title) {
			//use title as key?
			if(is_numeric($key)) {
				$key = str_replace(' ', '_', strtolower($title));
			}
			//add column
			$this->columns[$key] = $title;
		}
		//chain it
		return $this;
	}

	public function orderColumns(array $keys) {
		//set vars
		$newCols = [];
		//guess columns
		$this->getColumns();
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

	public function addColumn($key, $title=null) {
		//format key?
		if(!is_array($key)) {
			//format key?
			if($title === null) {
				$title = $key;
				$key = str_replace(' ', '_', strtolower($title));
			}
			//format as array
			$key = [ $key => $title ];
		}
		//return
		return $this->setColumns($key, false);
	}

	public function removeColumn($key) {
		//remove column?
		if(isset($this->columns[$key])) {
			unset($this->columns[$key]);
		}
		//chain it
		return $this;
	}

	public function getData() {
		return $this->data;
	}

	public function setData(array $data, $replace=true) {
		//reset?
		if($replace) {
			$this->data = [];
		}
		//add to data
		foreach($data as $k => $v) {
			$this->data[$k] = $v;
		}
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

	public function addRow(array $row) {
		//add row
		$this->data[] = $row;
		//chain it
		return $this;
	}

	public function removeRow($key) {
		//remove row?
		if(isset($this->data[$key])) {
			unset($this->data[$key]);
		}
		//chain it
		return $this;
	}

	public function addRowAction($action, $url) {
		//set vars
		$url = urldecode($url);
		//add column
		$this->addColumn('Actions');
		//filter cell
		$this->filterCell('actions', function($html, $row) use($action, $url) {
			//url placeholders
			foreach($row as $k => $v) {
				//format value?
				if(!is_string($v) && !is_numeric($v)) {
					$v = '';
				}
				//update url
				$url = str_replace('{' . $k . '}', $v, $url);
			}
			//add action link
			$html .= '<a href="' . $url . '">' . ucfirst($action) . '</a>' . "\n";
			//return
			return $html;
		});
		//return
		return $this;
	}

	public function filterCell($column, $callback = null) {
		//has callback?
		if($callback === null) {
			$callback = $column;
			$column = null;
		}
		//add callback
		$this->filters[] = [
			'column' => $column,
			'callback' => $callback,
		];
		//chain it
		return $this;
	}

	public function render() {
		//get columns?
		if(!$this->columns) {
			$this->getColumns();
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
		//set vars
		$html = '';
		//set id attr
		$this->attr['id'] = $this->name . '-table';
		//reset data keys
		$this->data = array_values($this->data);
		//open wrapper
		$html .= '<div id="' . $this->name . '-table-wrap" class="table-wrap">' . "\n";
		//add before table
		$html .= implode("\n", $this->html['before']);
		//has data?
		if($this->data) {
			//open table
			$html .= '<table' . Html::formatAttr($this->attr). '>' . "\n";
			//open table head
			$html .= '<thead>' . "\n";
			//open columns
			$html .= '<tr>';
			//set columns
			foreach($this->columns as $col => $cell) {
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
				foreach($this->columns as $col => $colTitle) {
					//get cell value
					$cell = isset($row[$col]) ? $row[$col] : '';
					//get column position
					$colPos = array_flip(array_keys($this->columns))[$col];
					//filter cell value
					foreach($this->filters as $filter) {
						//skip filter?
						if($filter['column'] && $filter['column'] !== $col) {
							continue;
						}
						//execute filter
						$tmp = call_user_func($filter['callback'], $cell, $row, $col, $colPos);
						//update value?
						if($tmp !== null) {
							$cell = $tmp;
						}
					}
					//add cell
					$html .= '<td>' . str_replace("\n", "<br>", $this->printCell($cell)) . '</td>';
				}
				//close row
				$html .= '</tr>' . "\n";
			}
			//close table body
			$html .= '</tbody>' . "\n";
			//close table
			$html .= '</table>' . "\n";
		} else {
			//nothing to render
			$html .= '<div class="table-not-found">' . str_replace('{name}', $this->name, $this->notFoundMsg) . '</div>';		
		}
		//add after table
		$html .= implode("\n", $this->html['after']);
		//close table wrap
		$html .= '</div>' . "\n";
		//return
		return $html;
	}

	protected function printCell($input, array $keys=[]) {
		//is array?
		if(is_array($input) || is_object($input)) {
			//set vars
			$tmp = '';
			//loop through input
			foreach($input as $k => $v) {
				$nKeys = $keys;
				$nKeys[] = $k;
				$tmp .= $this->printCell($v, $nKeys) . "\n";
			}
			//update input
			$input = $tmp;
		} else {
			//transform vars
			if($input === true) {
				$input = 'TRUE';
			} else if($input === false) {
				$input = 'FALSE';
			} else if($input === null) {
				$input = 'NULL';
			}
			//add keys?
			if($keys) {
				$input = implode('.', $keys) . ": " . $input;
			}
		}
		//return
		return trim($input);
	}

}