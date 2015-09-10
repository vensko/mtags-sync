<?php

error_reporting(-1);

$tagSync = new TagSync();
$tagSync->parseArgs();
$tagSync->indexLibrary();
$tagSync->sync();

/**
 * Class TagSync
 */
class TagSync
{
	const VERSION = '1.0';
	const EXT = 'tags';
	const WIN_EXT = 'wfio';
	const WIN_WRAPPER = 'wfio://';
	const ORPHAN_DIR = '!DELETED';

	public $srcDir, $destDir, $relativePaths, $orphanDir;

	/**
	 * @var LibraryItem[]
	 */
	public $library = [];

	public $needDurations = [];
	//public $durations = [];
	public $destBrokenJSON = [];
	public $destNoDurations = [];
	public $brokenLibraryFiles = [];
	public $destDuplicates = [];
	public $destChdirFailed = [];
	public $srcParsingFailed = [];
	public $srcNoDurations = [];
	public $failedWrites = [];
	public $failedDeletes = [];
	public $srcMkdirFailed = [];
	public $win = '';
	public $dirTags = '!';
	public $isWindows = false;
	public $dir_chmod = 0755;
	public $id3;

	public $mediaExtensions = ['flac','mp3','m4a'];

	/**
	 * Parses command lines parameters
	 */
	public function __construct()
	{
		if (DIRECTORY_SEPARATOR === '\\') {
			$this->isWindows = true;
			// https://github.com/kenjiuno/php-wfio
			if (extension_loaded(static::WIN_EXT)) {
				$this->win = static::WIN_WRAPPER;
			} else {
				echo "php_wfio not loaded, the script won't work correctly with unicode file names.\n\n";
			}
		}

		require_once(__DIR__.'/getID3/getid3/getid3.php');

		$this->id3 = new getID3;
		$this->id3->win = $this->win;
	}

	public function parseArgs()
	{
		$args = CommandLine::parseArgs();

		$this->srcDir = isset($args[0]) ? $args[0] : null;
		$this->destDir = isset($args[1]) ? $args[1] : null;
		$this->orphanDir = isset($args['move-orphaned']) ? $args['move-orphaned'] : false;
		$useRelative = isset($args['no-relative']) ? (bool)$args['no-relative'] : true;

		if (isset($args['dir-tags'])) {
			$this->dirTags = $args['dir-tags'];
		}

		if ($this->isWindows && (!$this->srcDir || !$this->destDir) && file_exists(wfio_getcwd8().DIRECTORY_SEPARATOR.'folderbrowse.exe')) {
			$initialDir = mb_substr(wfio_getcwd8(), 0, 2);
			$this->srcDir = $this->mb_trim(trim(shell_exec('folderbrowse.exe "Choose source directory:" '.$initialDir)), DIRECTORY_SEPARATOR);
			$this->destDir = $this->mb_trim(trim(shell_exec('folderbrowse.exe "Choose destination directory:" '.$initialDir)), DIRECTORY_SEPARATOR);
		}

		if (!$this->srcDir || !$this->destDir) {
			echo "Usage:\nmtags-sync source-directory destination-directory [--dir-tags=!] [--no-relative] [--move-orphaned[=path]]\n\n";
			passthru('pause');
			exit;
		}

		if ($this->isWindows) {
			if (mb_detect_encoding($this->srcDir, 'UTF-7, UTF-8')) {
				$this->srcDir = wfio_path2utf8($this->srcDir);
			}

			if (mb_detect_encoding($this->destDir, 'UTF-7, UTF-8')) {
				$this->destDir = wfio_path2utf8($this->destDir);
			}
		}

		if (!is_dir($this->win.$this->destDir)) {
			if (!mkdir($this->win.$this->destDir, $this->dir_chmod, true)) {
				echo "Could not create the destination directory, please, create it manually.";
				exit;
			}
		}

		if ($this->orphanDir && is_bool($this->orphanDir)) {
			$this->orphanDir = $this->destDir.DIRECTORY_SEPARATOR.static::ORPHAN_DIR;
		}

		if ($this->orphanDir && is_string($this->orphanDir) && !is_dir($this->win.$this->orphanDir)) {
			if (!mkdir($this->win.$this->orphanDir, $this->dir_chmod, true)) {
				echo "Could not create directory for orphaned tags ".$this->orphanDir.", please, create it manually.";
				exit;
			}
		}

		$this->relativePaths = $useRelative && (mb_strtolower(mb_substr($this->srcDir, 0, 2)) === mb_strtolower(mb_substr($this->destDir, 0, 2)));
	}

	/**
	 * Indexes destination directory
	 */
	public function indexLibrary()
	{
		echo "Indexing the library...\n\n";

		$files = $this->scanDirectories($this->destDir, true);

		foreach ($files as $fileName) {
			if (!$json = $this->parseTags($fileName)) {
				$this->destBrokenJSON[] = $fileName;
				echo "- Broken or empty file, skipping:\n".$fileName."\n\n";
				continue;
			}

			if (!$libraryId = $this->getDurationId($json)) {
				$this->destNoDurations[] = $fileName;
				echo "- No durations, skipping:\n".$fileName."\n\n";
				continue;
			}

			if (isset($this->library[$libraryId])) {
				if (!isset($this->destDuplicates[$libraryId])) {
					$this->destDuplicates[$libraryId][] = $this->library[$libraryId][0];
				}
				$this->destDuplicates[$libraryId][] = $fileName;
				echo "- Possible duplicate, skipping:\n".$fileName."\n\n";
				continue;
			}

			$this->library[$libraryId] = new LibraryItem($this, $libraryId, $fileName, $json);

			$brokenFile = false;

			for ($i = 0, $l = count($json); $i < $l; $i++) {
				$f = $json[$i];

				if (empty($f['@'])) {
					echo ". No path, preparing to fix:\n".$fileName."|".$i."\n\n";
					$this->brokenLibraryFiles[$libraryId][] = $i;
				} else {
					$realSrcFile = $f['@'];
					if (mb_substr($realSrcFile, 0, 1) === '/') {
						$realSrcFile = mb_substr($f['@'], 1);
					}
					$realSrcFile = mb_split('[|]', $realSrcFile)[0];

					if ($this->relativePaths) {
						$realSrcFile = $this->mb_dirname($fileName).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $realSrcFile);
					}

					if (!file_exists($this->win.$realSrcFile)) {
						if (!$brokenFile) {
							echo ". Broken path, preparing to fix:\n".$fileName.".\n";
							$brokenFile = true;
						}
						echo $this->mb_basename($realSrcFile)."\n";
						$this->brokenLibraryFiles[$libraryId][] = $i+1;
					}
				}

				//$duration = mb_substr($f['DURATION'], 0, -1);
				//$this->durations[$duration][] = [$libraryId, $i];
			}

			if ($brokenFile) {
				echo "\n";
			}
		}
	}

	public function analyzeDirectory($files)
	{
		if (is_string($files)) {
			$files = array_map(function($file) use($files) {
				return $files.DIRECTORY_SEPARATOR.$file;
			}, array_diff(scandir(mb_detect_encoding($files) === 'UTF-8' ? $files : $this->win.$files), ['..', '.']));
		}

		$results = [];

		foreach ($files as $file) {
			$ext = $this->mb_ext($file);

			if (!in_array($ext, $this->mediaExtensions)) {
				continue;
			}

			$data = $this->id3->analyze($file);

			if (!$data) {
				continue;
			}

			$entry = [
				'@' => ($this->isWindows ? '/' : '').str_replace(DIRECTORY_SEPARATOR, '/', $file)
			];

			if (!isset($data['playtime_seconds'])) {
				echo "Couldn't determine duration of ".$file.", skipping.\n";
				continue;
			}

			$entry['DURATION'] = mb_split("[.]", $data['playtime_seconds']);
			$entry['DURATION'] = $entry['DURATION'][0].'.'.substr($entry['DURATION'][1], 0, 7);

			$tags = [];

			switch ($ext) {
				case 'flac':
					$tags = !empty($data[$ext]['comments']) ? $data[$ext]['comments'] : [];
					break;
				case 'mp3':
					$tags = !empty($data['tags']['id3v2']) ? $data['tags']['id3v2'] : (!empty($data['tags']['id3v1']) ? $data['tags']['id3v1'] : []);
					break;
				case 'm4a':
					$tags = !empty($data['tags']['quicktime']) ? $data['tags']['quicktime'] : [];
			}

			if ($tags) {
				$entry += array_map(function($tag) {
					return count($tag) === 1 ? $tag[0] : $tag;
				}, $tags);
			}

			$results[] = $entry;
		}

		return $results;
	}

	/**
	 * Syncs destination directory with source
	 */
	public function sync()
	{
		echo "\n\nIndexing source files...\n\n";

		$files = $this->scanDirectories($this->srcDir, false);

		if (!$files) {
			echo "No source files found.\n";
			return;
		}

		foreach ($files as $fileName) {
			if (is_array($fileName)) {
				if (!$json = $this->analyzeDirectory($fileName[1])) {
					echo "No candidates found in ".$fileName[0].", skipping.\n\n";
					continue;
				}
				$fileName = $fileName[0];
			} else if (!$json = $this->parseTags($fileName)) {
				echo "- Broken or empty file, skipping:\n".$fileName."\n\n";
				$this->srcParsingFailed[] = $fileName;
				continue;
			}

			if (!$libraryId = $this->getDurationId($json)) {
				$this->srcNoDurations[] = $fileName;
				echo "- No durations, skipping:\n".$fileName."\n\n";
				continue;
			}

			if (isset($this->library[$libraryId]) && !isset($this->brokenLibraryFiles[$libraryId])) {
				$this->library[$libraryId]->exists = true;
				continue;
			}

			if (isset($this->library[$libraryId])) {
				$libraryItem = $this->library[$libraryId];
				echo ". Found source to fix file paths:\n".$libraryItem->realPath."\n-> ".$fileName."\n";
			} else {
				$libraryItem = $libraryId;
				echo ". New release to add:\n".$fileName."\n";
			}

			if ($libraryItem = $this->saveToLibrary($libraryId, $libraryItem, $fileName, $json)) {
				$libraryItem->exists = true;
				$this->library[$libraryId] = $libraryItem;
				echo "+ Successfully saved.\n\n";
			}
		}

		$index = 0;
		foreach ($this->library as $id => $data) {
			if (!$data->exists) {
				if ($this->orphanDir) {
					echo "* Files don't exist anymore, moving to ".$this->mb_basename($this->orphanDir).":\n".$data->realPath."\n\n";
					rename($this->win.$this->library[$id]->realPath, $this->win.$this->orphanDir.DIRECTORY_SEPARATOR.date('Y-d-m H-i-ss').'-'.++$index.' '.$this->mb_basename($this->library[$id]->realPath));
				} else {
					echo "* Files don't exist anymore, can be safely removed:\n".$data->realPath."\n(Use the --move-orphaned parameter to move it automatically.)\n\n";
				}
			}
		}
	}

	/**
	 * @param string $libraryId
	 * @param string|LibraryItem $libraryItem
	 * @param string $file
	 * @param array $json
	 * @return bool|LibraryItem
	 */
	protected function saveToLibrary($libraryId, $libraryItem, $file, array $json)
	{
		$isDir = $this->mb_ext($file) !== static::EXT;
		$filePathInDir = mb_substr($file, mb_strlen($this->srcDir) + 1);

		if ($isDir) {
			$destinationFile = $this->destDir.DIRECTORY_SEPARATOR.$this->mb_basename($file).'.'.static::EXT;
		} else if ($this->mb_basename($filePathInDir, '.'.static::EXT) === $this->dirTags) {
			$dirPathInDir = mb_strpos($filePathInDir, DIRECTORY_SEPARATOR) === false
				? $this->mb_basename($filePathInDir, '.'.static::EXT)
				: $this->mb_dirname($filePathInDir);

			$destinationFile = $this->destDir.DIRECTORY_SEPARATOR.$dirPathInDir.'.'.static::EXT;
		} else {
			$destinationFile = $this->destDir.DIRECTORY_SEPARATOR.$filePathInDir;
		}

		if (!$libraryItem instanceof LibraryItem) {
			$libraryItem = new LibraryItem($this, $libraryId, $destinationFile, $json);
		}

		if ($libraryItem->save($isDir ? null : $file)) {
			return $libraryItem;
		} else {
			$this->failedWrites[] = $destinationFile;
			return false;
		}
	}

	/**
	 * @param string $file Real file path
	 * @return array|bool
	 */
	public function parseTags($file)
	{
		$json = file_get_contents($this->win.$file, FILE_TEXT);

		if (!$json) {
			return false;
		}

		if (substr($json, 0, 3) == pack('CCC', 239, 187, 191)) {
			$json = substr($json, 3);
		}

		$json = json_decode($json, true);

		return is_array($json) ? $json : false;
	}

	/**
	 * @param array $data
	 * @return null|string
	 */
	protected function getDurationId(array $data)
	{
		$key = 'DURATION';
		$keys = array_reduce($data, function ($result, $array) use ($key) {
			if (isset($array[$key])) {
				$result[] = substr($array[$key], 0, -1);
			} else if (isset($array['@'])) {
				echo "No duration found for ".$array['@']."\n";
			}
			return $result;
		}, []);

		return implode('|', $keys);
	}

	/**
	 *
	 * Find the relative file system path between two file system paths
	 * https://gist.github.com/ohaal/2936041
	 *
	 * @param  string $fromPath Path to start from
	 * @param  string $toPath Path we want to end up in
	 *
	 * @return string             Path leading from $frompath to $topath
	 */
	public function findRelativePath($fromPath, $toPath)
	{
		$from = mb_split('['.preg_quote(DIRECTORY_SEPARATOR).']', $fromPath); // Folders/File
		$to = mb_split('['.preg_quote(DIRECTORY_SEPARATOR).']', $toPath); // Folders/File
		$relPath = '';

		$i = 0;
		// Find how far the path is the same
		while (isset($from[$i]) && isset($to[$i])) {
			if ($from[$i] != $to[$i]) break;
			$i++;
		}
		$j = count($from) - 1;
		// Add '..' until the path is the same
		while ($i <= $j) {
			if (!empty($from[$j])) $relPath .= '..'.DIRECTORY_SEPARATOR;
			$j--;
		}
		// Go to folder from where it starts differing
		while (isset($to[$i])) {
			if (!empty($to[$i])) $relPath .= $to[$i].DIRECTORY_SEPARATOR;
			$i++;
		}

		// Strip last separator
		return mb_substr($relPath, 0, -1);
	}

	/**
	 * Recursively walks through directory tree and collects file names with needed extensions
	 *
	 * @param string $rootDir
	 * @param bool $onlyTags
	 * @param array $result
	 * @return array
	 */
	function scanDirectories($rootDir, $onlyTags = false, $result = [])
	{
		$contents = scandir($this->win.$rootDir);
		$contents = array_diff($contents, ['..', '.']);
		$dirAdded = false;

		foreach ($contents as $path) {
			if (is_dir($this->win.$rootDir.DIRECTORY_SEPARATOR.$path)) {
				$result = $this->scanDirectories($rootDir.DIRECTORY_SEPARATOR.$path, $onlyTags, $result);
			} else {
				$ext = $this->mb_ext($path);

				if ($ext === static::EXT) {
					$result[] = $rootDir.DIRECTORY_SEPARATOR.$path;
				} else if (!$onlyTags && !$dirAdded && in_array($ext, $this->mediaExtensions)) {
					$result[] = [
						$rootDir,
						array_map(function ($file) use ($rootDir) {
							return $rootDir.DIRECTORY_SEPARATOR.$file;
						}, $contents)
					];
					$dirAdded = true;
				}
			}
		}

		return $result;
	}

	/**
	 * basename() with unicode support
	 *
	 * @param string $path
	 * @param string|null $suffix
	 * @return string
	 */
	public function mb_basename($path, $suffix = null)
	{
		$path = $this->mb_str_replace('/', DIRECTORY_SEPARATOR, $path);
		$path = mb_strrpos($path, DIRECTORY_SEPARATOR) === false ? $path : mb_substr($path, mb_strrpos($path, DIRECTORY_SEPARATOR) + 1);
		if ($suffix !== null && preg_match('/'.$suffix.'$/u', $path)) {
			$path = mb_substr($path, 0, mb_strlen($path) - mb_strlen($suffix));
		}
		return $path;
	}

	/**
	 * dirname() with unicode support
	 *
	 * @param string $path
	 * @return string
	 */
	public function mb_dirname($path)
	{
		$path = $this->mb_str_replace('/', DIRECTORY_SEPARATOR, $path);
		return mb_substr($path, 0, mb_strrpos($path, DIRECTORY_SEPARATOR));
	}

	/**
	 * Extracts extension from unicode file name
	 *
	 * @param string $path
	 * @return string
	 */
	public function mb_ext($path)
	{
		return mb_strrpos($path, '.') === false ? '' : mb_strtolower(mb_substr($path, mb_strrpos($path, '.') + 1));
	}

	/**
	 * @param $string
	 * @param null $charlist
	 * @return mixed|string
	 */
	function mb_trim($string, $charlist = null)
	{
		if (is_null($charlist)) {
			return trim($string);
		} else {
			$charlist = str_replace('/', '\/', preg_quote($charlist));
			return preg_replace("/(^[$charlist]+)|([$charlist]+$)/us", '', $string);
		}
	}

	/**
	 * Replace all occurrences of the search string with the replacement string. Multibyte safe.
	 *
	 * @param string|array $search The value being searched for, otherwise known as the needle. An array may be used to designate multiple needles.
	 * @param string|array $replace The replacement value that replaces found search values. An array may be used to designate multiple replacements.
	 * @param string|array $subject The string or array being searched and replaced on, otherwise known as the haystack.
	 *                              If subject is an array, then the search and replace is performed with every entry of subject, and the return value is an array as well.
	 * @param string $encoding The encoding parameter is the character encoding. If it is omitted, the internal character encoding value will be used.
	 * @param int $count If passed, this will be set to the number of replacements performed.
	 * @return array|string
	 */
	function mb_str_replace($search, $replace, $subject, $encoding = 'auto', &$count = 0)
	{
		if (!is_array($subject)) {
			$searches = is_array($search) ? array_values($search) : [$search];
			$replacements = is_array($replace) ? array_values($replace) : [$replace];
			$replacements = array_pad($replacements, count($searches), '');
			foreach ($searches as $key => $search) {
				$replace = $replacements[$key];
				$search_len = mb_strlen($search, $encoding);

				$sb = [];
				while (($offset = mb_strpos($subject, $search, 0, $encoding)) !== false) {
					$sb[] = mb_substr($subject, 0, $offset, $encoding);
					$subject = mb_substr($subject, $offset + $search_len, null, $encoding);
					++$count;
				}
				$sb[] = $subject;
				$subject = implode($replace, $sb);
			}
		} else {
			foreach ($subject as $key => $value) {
				$subject[$key] = $this->mb_str_replace($search, $replace, $value, $encoding, $count);
			}
		}
		return $subject;
	}
}

/**
 * Class LibraryItem
 */
class LibraryItem
{
	public $sync, $id, $realPath, $data, $exists;

	/**
	 * @param TagSync $sync
	 * @param string $libraryId
	 * @param string $file
	 * @param array $data
	 */
	public function __construct(TagSync $sync, $libraryId, $file, array $data)
	{
		$this->sync = $sync;
		$this->data = $data;
		$this->id = $libraryId;
		$this->realPath = $file;
	}

	/**
	 * Saves the library item
	 *
	 * @param string|null $pathsSource Optional source of file paths
	 * @return bool|int
	 */
	public function save($pathsSource = null)
	{
		if ($pathsSource) {
			$metaPath = $this->sync->relativePaths
				? $this->sync->findRelativePath($this->sync->mb_dirname($this->realPath), $this->sync->mb_dirname($pathsSource))
				: ($this->sync->isWindows ? '/' : '').$this->sync->mb_dirname($pathsSource);

			$metaPath = $this->sync->mb_str_replace(DIRECTORY_SEPARATOR, '/', $metaPath);

			$newJSON = $this->sync->parseTags($pathsSource);

			if (count($newJSON) !== count($this->data)) {
				echo $this->realPath.' and '.$pathsSource." have different number of files, skipping.\n";
				return false;
			}

			for ($i = 0, $l = count($newJSON); $i < $l; $i++) {
				if (!isset($newJSON[$i]['@'])) {
					return false;
				}

				$basename = $newJSON[$i]['@'];
				if (mb_substr($basename, 0, 1) === '/') {
					$basename = mb_substr($basename, 1);
				}
				$basename = $this->sync->mb_str_replace('/', DIRECTORY_SEPARATOR, $basename);
				$basename = mb_split('[|]', $basename)[0];
				$basename = $this->sync->mb_basename($basename);

				$index = mb_strpos($newJSON[$i]['@'], '|') !== false ? '|'.mb_split('[|]', $newJSON[$i]['@'])[1] : '';

				$this->data[$i]['@'] = $metaPath.'/'.$basename.$index;
			}
		}

		$destFileDir = $this->sync->mb_dirname($this->realPath);

		if (!file_exists($this->sync->win.$destFileDir)) {
			if (!mkdir($this->sync->win.$destFileDir, $this->sync->dir_chmod, true)) {
				echo "Failed to create directory ".$destFileDir.", skipping: ".$this->realPath;
				return false;
			}
			// Workaround for Samba asynchronicity
			$t = 0;
			while (!file_exists($this->sync->win.$destFileDir)) {
				$t++;
				if ($t === 20) {
					$this->sync->srcMkdirFailed[] = $destFileDir;
					return false;
				}
				sleep(0.5);
			}
		}

		echo "Saving: ".$this->realPath."\n";

		return file_put_contents(
			$this->sync->win.$this->realPath,
			chr(0xEF).chr(0xBB).chr(0xBF).json_encode(
				$this->data,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}
}

/**
 * CommandLine class
 *
 * Command Line Interface (CLI) utility class.
 *
 * @author              Patrick Fisher <patrick@pwfisher.com>
 * @since               August 21, 2009
 * @see                 https://github.com/pwfisher/CommandLine.php
 */
class CommandLine
{
	public static $args;

	/**
	 * PARSE ARGUMENTS
	 *
	 * This command line option parser supports any combination of three types of options
	 * [single character options (`-a -b` or `-ab` or `-c -d=dog` or `-cd dog`),
	 * long options (`--foo` or `--bar=baz` or `--bar baz`)
	 * and arguments (`arg1 arg2`)] and returns a simple array.
	 *
	 * [pfisher ~]$ php test.php --foo --bar=baz --spam eggs
	 *   ["foo"]   => true
	 *   ["bar"]   => "baz"
	 *   ["spam"]  => "eggs"
	 *
	 * [pfisher ~]$ php test.php -abc foo
	 *   ["a"]     => true
	 *   ["b"]     => true
	 *   ["c"]     => "foo"
	 *
	 * [pfisher ~]$ php test.php arg1 arg2 arg3
	 *   [0]       => "arg1"
	 *   [1]       => "arg2"
	 *   [2]       => "arg3"
	 *
	 * [pfisher ~]$ php test.php plain-arg --foo --bar=baz --funny="spam=eggs" --also-funny=spam=eggs \
	 * > 'plain arg 2' -abc -k=value "plain arg 3" --s="original" --s='overwrite' --s
	 *   [0]       => "plain-arg"
	 *   ["foo"]   => true
	 *   ["bar"]   => "baz"
	 *   ["funny"] => "spam=eggs"
	 *   ["also-funny"]=> "spam=eggs"
	 *   [1]       => "plain arg 2"
	 *   ["a"]     => true
	 *   ["b"]     => true
	 *   ["c"]     => true
	 *   ["k"]     => "value"
	 *   [2]       => "plain arg 3"
	 *   ["s"]     => "overwrite"
	 *
	 * Not supported: `-cd=dog`.
	 *
	 * @param               array|null $argv
	 * @author              Patrick Fisher <patrick@pwfisher.com>
	 * @since               August 21, 2009
	 * @see                 https://github.com/pwfisher/CommandLine.php
	 * @see                 http://www.php.net/manual/en/features.commandline.php
	 *                      #81042 function arguments($argv) by technorati at gmail dot com, 12-Feb-2008
	 *                      #78651 function getArgs($args) by B Crawford, 22-Oct-2007
	 * @usage               $args = CommandLine::parseArgs($_SERVER['argv']);
	 *
	 * @return              array
	 */
	public static function parseArgs($argv = null)
	{
		$argv = $argv ? $argv : $_SERVER['argv'];

		array_shift($argv);
		$out = [];
		$key = '';

		for ($i = 0, $j = count($argv); $i < $j; $i++) {
			$arg = $argv[$i];

			// --foo --bar=baz
			if (mb_substr($arg, 0, 2) === '--') {
				$eqPos = mb_strpos($arg, '=');

				// --foo
				if ($eqPos === false) {
					$key = mb_substr($arg, 2);

					// --foo value
					if ($i + 1 < $j && $argv[$i + 1][0] !== '-') {
						$value = $argv[$i + 1];
						$i++;
					} else {
						$value = isset($out[$key]) ? $out[$key] : true;
					}
					$out[$key] = $value;
				} // --bar=baz
				else {
					$key = mb_substr($arg, 2, $eqPos - 2);
					$value = mb_substr($arg, $eqPos + 1);
					$out[$key] = $value;
				}
			} // -k=value -abc
			else if (mb_substr($arg, 0, 1) === '-') {
				// -k=value
				if (mb_substr($arg, 2, 1) === '=') {
					$key = mb_substr($arg, 1, 1);
					$value = mb_substr($arg, 3);
					$out[$key] = $value;
				} // -abc
				else {
					$chars = str_split(mb_substr($arg, 1));
					foreach ($chars as $char) {
						$key = $char;
						$value = isset($out[$key]) ? $out[$key] : true;
						$out[$key] = $value;
					}
					// -a value1 -abc value2
					if ($i + 1 < $j && $argv[$i + 1][0] !== '-') {
						$out[$key] = $argv[$i + 1];
						$i++;
					}
				}
			} // plain-arg
			else {
				$value = $arg;
				$out[] = $value;
			}
		}

		self::$args = $out;

		return $out;
	}

	/**
	 * @param $key
	 * @param bool|false $default
	 * @return bool|string
	 */
	public static function getBoolean($key, $default = false)
	{
		if (!isset(self::$args[$key])) {
			return $default;
		}
		$value = self::$args[$key];

		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return (bool)$value;
		}

		if (is_string($value)) {
			$value = strtolower($value);
			$map = [
				'y' => true,
				'n' => false,
				'yes' => true,
				'no' => false,
				'true' => true,
				'false' => false,
				'1' => true,
				'0' => false,
				'on' => true,
				'off' => false,
			];
			if (isset($map[$value])) {
				return $map[$value];
			}
		}

		return $default;
	}
}

function dd(){
	foreach (func_get_args() as $arg) {
		var_dump($arg);
	}
	exit;
};