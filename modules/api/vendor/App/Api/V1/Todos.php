<?php

namespace App\Api\V1;

class Todos extends \Prototypr\Route {

	public $path = 'v1/todos/:id?';
	public $methods = [ 'GET', 'POST', 'PUT', 'DELETE' ];
	public $auth = false;
	public $public = true;

	protected $inputSchema = [
		'id' => [
			'label' => 'Todo ID',
			'desc' => 'Todo item ID',
			'type' => 'integer.hidden',
			'contexts' => [
				'GET' => [
					'required' => false,
					'source' => 'url',
					'default' => null,
				],
				'PUT' => [
					'required' => true,
					'source' => 'url',
					'default' => null,
				],
				'DELETE' => [
					'required' => true,
					'source' => 'url',
					'default' => null,
				],
			],
			'rules' => [],
			'filters' => [],
		],
		'title' => [
			'label' => 'Title',
			'desc' => 'Todo item title',
			'type' => 'string',
			'contexts' => [
				'POST' => [
					'required' => true,
					'source' => 'body',
					'default' => null,
				],
				'PUT' => [
					'required' => false,
					'source' => 'body',
					'default' => null,
				],
			],
			'rules' => [
				'length(3,64)',
			],
			'filters' => [],
		],
		'description' => [
			'label' => 'Description',
			'desc' => 'Todo item description',
			'type' => 'string.textarea',
			'contexts' => [
				'POST' => [
					'required' => false,
					'source' => 'body',
					'default' => null,
				],
				'PUT' => [
					'required' => false,
					'source' => 'body',
					'default' => null,
				],
			],
			'rules' => [
				'length(3,256)',
			],
			'filters' => [],
		],
		'status' => [
			'label' => 'Status',
			'desc' => 'Todo item status',
			'type' => 'integer.select',
			'options' => [
				1 => 'To do',
				2 => 'In progress',
				3 => 'Done',
			],
			'contexts' => [
				'POST' => [
					'required' => false,
					'source' => 'body',
					'default' => 1,
				],
				'PUT' => [
					'required' => false,
					'source' => 'body',
					'default' => null,
				],
			],
			'rules' => [],
			'filters' => [],
		],
		'assignee' => [
			'label' => 'Assignee',
			'multiple' => false,
			'children' => [
				'first_name' => [
					'label' => 'First name',
					'desc' => 'Assignee first name',
					'type' => 'string.text',
					'contexts' => [
						'POST' => [
							'required' => false,
							'source' => 'body',
							'default' => null,
						],
						'PUT' => [
							'required' => false,
							'source' => 'body',
							'default' => null,
						],
					],
					'rules' => [
						'length(3,32)',
					],
					'filters' => [],
				],
				'last_name' => [
					'label' => 'Last name',
					'desc' => 'Assignee last name',
					'type' => 'string.text',
					'contexts' => [
						'POST' => [
							'required' => false,
							'source' => 'body',
							'default' => null,
						],
						'PUT' => [
							'required' => false,
							'source' => 'body',
							'default' => null,
						],
					],
					'rules' => [
						'length(3,32)',
					],
					'filters' => [],
				],
			],
		],
		'extras' => [
			'label' => 'Extras',
			'multiple' => true,
			'children' => [
				'file_path' => [
					'label' => 'Add note here',
					'desc' => 'Add some notes here',
					'type' => 'string',
					'contexts' => [
						'POST' => [
							'required' => false,
							'source' => 'body',
							'default' => null,
						],
						'PUT' => [
							'required' => false,
							'source' => 'body',
							'default' => null,
						],
					],
					'rules' => [
						'length(3,256)',
					],
					'filters' => [],
				],
			],
		],
	];

	protected $outputSchema = [];

	protected function doGet(array $input, array $output) {
		//get records
		$records = $this->read($input);
		//success?
		if(is_array($records)) {
			$output['data'] = [
				'count' => count($records),
				'records' => $records,
			];
		} else {
			$output['errors'][] = 'Read failed';
		}
		//return
		return $output;
	}

	protected function doPost(array $input, array $output) {
		//save record
		$id = $this->save($input);
		//record saved?
		if(!empty($id)) {
			$output['data']['id'] = $id;
			$output['data']['saved'] = true;
		} else {
			$output['errors'][] = 'Save failed';
		}
		//return
		return $output;
	}

	protected function doPut(array $input, array $output) {
		return $this->doPost($input, $output);
	}

	protected function doDelete(array $input, array $output) {
		//delete records
		$ids = $this->delete($input);
		//successful?
		if(is_array($ids)) {
			$output['data']['ids'] = $ids;
			$output['data']['deleted'] = count($ids);
		} else {
			$output['errors'][] = 'Delete failed';
		}
		//return
		return $output;
	}

	protected function onProcessInput(array $input) {
		//remove all empty values
		foreach($input as $k => $v) {
			//is array?
			if(is_array($v)) {
				//recursive process
				if($input[$k] = $this->onProcessInput($v)) {
					//get first key
					$firstKey = array_keys($input[$k])[0];
					//reset keys?
					if(is_numeric($firstKey)) {
						$input[$k] = array_values($input[$k]);
					}
				}
			}
			//remove key?
			if(empty($input[$k])) {
				unset($input[$k]);
			}
		}
		//return
		return $input;
	}

	protected function read(array $filters) {
		//get data
		$json = $this->kernel->cache('todos') ?: [];
		//filter data?
		if(!empty($filters)) {
			//loop through filters
			foreach($json as $id => $meta) {
				//set vars
				$found = false;
				//loop through filters
				foreach($filters as $key => $val) {
					//match found?
					if(isset($json[$id][$key]) && $json[$id][$key] == $val) {
						$found = true;
						break;
					}
				}
				//remove?
				if(!$found) {
					unset($json[$id]);
				}
			}
		}
		//return
		return $json;
	}

	protected function save(array $record) {
		//get data
		$json = $this->kernel->cache('todos') ?: [];
		//set record ID?
		if(!isset($record['id']) || !$record['id']) {
			$record['id'] = count($json) + 1;
		}
		//put ID first in array
		$record = [ 'id' => $record['id'] ] + $record;
		//get old record
		$old = isset($json[$record['id']]) ? $json[$record['id']] : [];
		//add record
		$json[$record['id']] = array_merge($old, $record);
		//save cache
		if(!$this->kernel->cache('todos', $json)) {
			return false;
		}
		//return
		return $record['id'];
	}

	protected function delete(array $filters) {
		//get data
		$ids = [];
		$json = $this->kernel->cache('todos') ?: [];
		//loop through filters
		foreach($json as $id => $meta) {
			//loop through filters
			foreach($filters as $key => $val) {
				//match found?
				if(isset($json[$id][$key]) && $json[$id][$key] == $val) {
					$ids[] = $id;
					unset($json[$id]);
					break 2;
				}
			}
		}
		//can save?
		if($ids && !$this->kernel->cache('todos', $json)) {
			return false;
		}	
		//return
		return $ids;
	}

}