<?php

namespace NhnEdu\DoorayTaskSync;

class LocalCache {

	private $_data = [];
	
	public function putData($key, $value) {
		$this->_data[$key] = $value;
	}

	public function getData($key) {
		return $this->_data[$key];
	}
}
