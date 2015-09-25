<?php

$destFile = __DIR__.'/bin/mtags.phar';

if (file_exists($destFile)) {
	unlink($destFile);
}

$phar = new Phar($destFile);

$phar->addFile(__DIR__.'/mtags.php', 'mtags.php');
$phar->setStub($phar->createDefaultStub('mtags.php'));

// for completeness
$phar->addFile(__FILE__, basename(__FILE__));
$phar->addFile(__DIR__.'/composer.json', 'composer.json');

foreach (glob(__DIR__.'/src/*.php') as $file) {
	$phar->addFile($file, 'src/'.basename($file));
}

$phar->addFile(__DIR__.'/vendor/autoload.php', 'vendor/autoload.php');

foreach (glob(__DIR__.'/vendor/composer/*.php') as $file) {
	$phar->addFile($file, 'vendor/composer/'.basename($file));
}

$phar->addFile(__DIR__.'/vendor/pwfisher/command-line-php/CommandLine.php', 'vendor/pwfisher/command-line-php/CommandLine.php');

$getid3dir = 'vendor/james-heinrich/getid3/getid3';

$getid3modules = [
	'getid3',
	'module.audio.',
	'module.misc.iso',
	'module.misc.cue',
	'module.tag.',
	'module.audio-video.riff',
	'module.audio-video.quicktime'
];

foreach (glob(__DIR__.'/'.$getid3dir.'/*.php') as $file) {
	$basename = basename($file);

	foreach ($getid3modules as $module) {
		if (strpos($basename, $module) === 0) {
			$phar->addFile($file, $getid3dir.'/'.$basename);
			break;
		}
	}
}

$dll = array_map(function($file){
	return '-d extension="%~dp0'.basename($file).'"';
}, glob(__DIR__.'/bin/*.dll'));
$dll = implode(' ', $dll);

$bat = <<<BAT
@echo off
@chcp 65001 >nul

set PHPRC=%~dp0

"%~dp0php.exe" {$dll} "%~dp0mtags.phar" %*
BAT;

file_put_contents(__DIR__.'/bin/mtags.bat', $bat);
