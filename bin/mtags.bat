@echo off
@chcp 65001 >nul

set PHPRC=%~dp0

"%~dp0php.exe" -d extension="%~dp0php_mbstring.dll" -d extension="%~dp0php_wfio.dll" "%~dp0mtags.phar" %*