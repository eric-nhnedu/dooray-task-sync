<?php

namespace NhnEdu\DoorayTaskSync;

class ConfigParser {
	public static function getConfig($filePath) {

		if (!file_exists($filePath)) {
			die("[Error] Not found configuration file. [".$filePath."]");
		}

		$json = json_decode(file_get_contents($filePath));
		return $json;
	}
}

