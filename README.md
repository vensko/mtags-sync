# mtags-sync
Command-line app to keep the m-TAGS (www.m-tags.org) library up to date with Unicode support.

NOT ready for usage yet!

# Usage
Windows:
```
mtags.bat source-directory destination-directory [options]
```
Linux:
```
php mtags.phar source-directory destination-directory [options]
```
Options:
```
--no-relative           Always write absolute paths.
--convert-paths         Convert existing paths, if they don't match the --no-relative option.
--move-orphaned[=path]  Move orphaned .tags to a [path]
--colored               Colored output.  
--verbose               Show even more messages.
--emulate               Don't do anything real.
--help                  Shows this info.
```

# Third-party components
- [getID3](https://github.com/JamesHeinrich/getID3)
- [php-wfio](https://github.com/kenjiuno/php-wfio)
