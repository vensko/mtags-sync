<?php

error_reporting(-1);
setlocale(LC_ALL, 'en_US.UTF-8');
define('DS', DIRECTORY_SEPARATOR);

require_once __DIR__.'/src/CommandLine.php';
require_once __DIR__.'/src/TagSync.php';
require_once __DIR__.'/src/LibraryItem.php';
require_once __DIR__.'/getID3/getid3/getid3.php';
require_once __DIR__.'/getID3/getid3/module.misc.cue.php';

$tagSync = new TagSync();
$tagSync->parseArgs();
$tagSync->sync();

echo "Done.\n";
exit;
