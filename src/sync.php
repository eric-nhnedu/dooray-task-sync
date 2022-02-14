<?php

require ('../vendor/autoload.php');

use NhnEdu\DoorayTaskSync\SyncTask;

$sync = new SyncTask('config.json');

$sync->autoSync();
