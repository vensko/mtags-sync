<?php

error_reporting(-1);
setlocale(LC_ALL, 'en_US.UTF-8');
define('DS', DIRECTORY_SEPARATOR);

require_once __DIR__.'/vendor/autoload.php';

$tagSync = new TagSync\TagSync();
$tagSync->parseArgs();
$tagSync->sync();

echo "Done.\n";
exit;
