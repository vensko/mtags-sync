# mtags-sync
Command-line app to keep the m-TAGS (www.m-tags.org) library up to date with Unicode support.

# Usage
Windows:
```
mtags.bat info media-file [options]
mtags.bat sync source-directory destination-directory [options]
```
Linux:
```
php mtags.phar info media-file [options]
php mtags.phar sync source-directory destination-directory [options]
```
Options:
```
--verbose               Show even more messages.
--colored               Colored output.
--emulate               Don't do anything real.
--help                  Show this info.
```
Sync options:
```
--no-relative           Always write absolute paths.
--convert-paths         Convert existing paths, if they don't match the --no-relative option.
--move-orphaned[=path]  Move orphaned .tags to a separate directory.
```

# Third-party components
- [getID3](https://github.com/JamesHeinrich/getID3)
- [php-wfio](https://github.com/kenjiuno/php-wfio)
