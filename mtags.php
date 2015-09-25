<?php

if (PHP_SAPI !== 'cli') {
	echo __FILE__." is intended to be used via command line interface only.";
	exit;
}

error_reporting(-1);
set_time_limit(0);
setlocale(LC_ALL, 'en_US.UTF-8');

require_once __DIR__.'/vendor/autoload.php';

$tagSync = new TagSync\TagSync();

switch ($tagSync->parseArgs()) {
	case 'sync':
		$tagSync->sync();
		break;
	case 'info':
		print_r($tagSync->analyze());
		break;
}
