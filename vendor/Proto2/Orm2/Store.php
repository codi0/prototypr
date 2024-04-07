<?php

namespace Proto2\Orm2;

class Store {

	protected $mapping;

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

	public function query($class, array $query) {
		//mock data
		return [
			'id' => isset($query['id']) ? $query['id'] : 1,
			'title' => 'Wow, what a post!',
			'description' => 'This is my cool article'
		];
	}

	public function sync($entity, array $changes) {
		//debug...
		print_r($changes);
		//return
		return true;
	}

}