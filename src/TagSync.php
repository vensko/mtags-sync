<?php

/**
 * Class TagSync
 */
class TagSync
{
	const VERSION = '0.1a';
	const EXT = 'tags';
	const WIN_EXT = 'wfio';
	const WIN_WRAPPER = 'wfio://';
	const ORPHAN_DIR = '!DELETED';
	const PATH_KEY = '@';
	const DURATION_KEY = 'DURATION';

	public $srcDir, $destDir, $destFile, $orphanDir;
	public $relativePaths = false;
	public $convertPaths = false;
	public $dir_chmod = 0755;

	/**
	 * @var array
	 */
	public $mediaTypes = [
		'flac' => 'vorbiscomment',
		'ape' => 'ape',
		'wv' => 'ape',
		'iso.wv' => 'ape',
		'm4a' => 'quicktime',
		'mp3' => ['id3v1', 'id3v2']
	];

	/**
	 * @var array
	 */
	public $nonUtf8Encodings = ['CP1251', 'CP1252', 'ISO-8859-1', 'ISO-8859-2', 'UCS-2', 'UTF-16BE'];

	/**
	 * @var bool
	 */
	public $isWindows = false;

	/**
	 * @var string
	 */
	public $win = '';

	/**
	 * @var getID3
	 */
	protected $id3;

	/**
	 * @var LibraryItem[]
	 */
	protected $library = [];

	/**
	 * @var array
	 */
	protected $brokenLibraryFiles = [];

	/**
	 * Constructor
	 */
	public function __construct()
	{
		if (DS === '\\') {
			$this->isWindows = true;
			// https://github.com/kenjiuno/php-wfio
			if (extension_loaded(static::WIN_EXT)) {
				$this->win = static::WIN_WRAPPER;
			} else {
				echo "php_wfio not loaded.";
				exit;
			}
		}

		getID3::$mtags = $this;

		$this->id3 = new getID3();
	}

	/**
	 * Parses user input
	 */
	public function parseArgs()
	{
		$args = CommandLine::parseArgs();

		$this->srcDir = !empty($args[0]) ? $args[0] : null;
		$this->destDir = !empty($args[1]) ? $args[1] : null;
		$this->orphanDir = !empty($args['move-orphaned']) ? $args['move-orphaned'] : false;
		$this->convertPaths = !empty($args['convert-paths']) ? (bool)$args['convert-paths'] : false;

		if (!$this->srcDir || !$this->destDir || isset($args['help'])) {
			$orphanDir = static::ORPHAN_DIR;
			$version = static::VERSION;
			echo <<<TXT
m-TAGS Sync {$version}

Usage:
mtags source-directory destination-directory [options]

Options:
--no-relative           Always write absolute paths.
--convert-paths         Convert existing paths, if they don't match the --no-relative option.
--move-orphaned[=path]  Move orphaned .tags to a separate directory ({$orphanDir} in a source directory by default)
--help                  Shows this info.

TXT;
			exit;
		}

		if ($this->isWindows) {
			$this->srcDir = wfio_path2utf8($this->srcDir);
			$this->destDir = wfio_path2utf8($this->destDir);
		}

		$this->srcDir = $this->truepath($this->srcDir) ?: $this->srcDir;
		$this->destDir = $this->truepath($this->destDir) ?: $this->destDir;

		if (!$this->destDir || !is_dir($this->win.$this->destDir)) {
			if (!$this->mkdir($this->destDir, $this->dir_chmod, true)) {
				echo "Failed to create the destination directory. Create it manually.";
				exit;
			}
		}

		if (!$this->srcDir || !is_dir($this->win.$this->srcDir)) {
			echo "Source directory not found.";
			exit;
		}

		if ($this->orphanDir && is_bool($this->orphanDir)) {
			$this->orphanDir = $this->destDir.DS.static::ORPHAN_DIR;
		}

		if ($this->orphanDir && is_string($this->orphanDir) && !is_dir($this->win.$this->orphanDir)) {
			if (!$this->mkdir($this->orphanDir, $this->dir_chmod, true)) {
				echo "Failed to create directory for orphaned tags ".$this->orphanDir.", please, create it manually.";
				exit;
			}
		}

		$this->relativePaths = isset($args['no-relative']) ? !(bool)$args['no-relative'] : true;

		if ($this->isWindows) {
			$this->relativePaths = $this->relativePaths && (mb_strtolower(mb_substr($this->srcDir, 0, 2)) === mb_strtolower(mb_substr($this->destDir, 0, 2)));
		}
	}

	/**
	 * Syncs destination directory with source
	 */
	public function sync()
	{
		$this->indexLibrary();

		echo "Indexing source files in ".$this->srcDir."\n\n";

		$dirs = $this->scanDirectories($this->srcDir, false);

		if (!$dirs) {
			echo "No source directories found.\n";
			return;
		}

		foreach ($dirs as list($dir, $files)) {
			if (!$mediums = $this->analyzeDirectory($dir, $files)) {
				echo "No candidates found in ".$dir.", skipping.\n\n";
				continue;
			}

			foreach ($mediums as $baseName => $medium) {
				if (!$libraryId = $this->getDurationId($medium)) {
					echo "- No durations, skipping:\n".$dir."\n\n";
					continue;
				}

				if (isset($this->library[$libraryId]) && !in_array($libraryId, $this->brokenLibraryFiles)) {
					$this->library[$libraryId]->exists = true;
					continue;
				}

				if (isset($this->library[$libraryId])) {
					$libraryItem = $this->library[$libraryId];
					echo ". Found directory to fix file paths in:\n".$libraryItem->realPath."\n-> ".$dir."\n";
					if ($this->library[$libraryId]->setPaths($medium) && $this->library[$libraryId]->save()) {
						echo "+ Successfully saved.\n\n";
					} else {
						echo "- Hmm, nothing changed.\n\n";
					}
				} else {
					$basePath = $baseName ? $dir.DS.$baseName : $dir;
					echo ". New release to add:\n".$basePath."\n";
					if ($libraryItem = $this->addToLibrary($libraryId, $basePath, $medium)) {
						$this->library[$libraryId] = $libraryItem;
						echo "+ Successfully saved.\n\n";
					}
				}
			}
		}

		$index = 0;
		foreach ($this->library as $id => $data) {
			if (!$data->exists) {
				if ($this->orphanDir) {
					echo "* Files don't exist anymore, moving to ".$this->basename($this->orphanDir).":\n".$data->realPath."\n\n";
					rename($this->win.$this->library[$id]->realPath, $this->win.$this->orphanDir.DS.date('Y-d-m H-i-ss').'-'.++$index.' '.$this->basename($this->library[$id]->realPath));
				} else {
					echo "* Files don't exist anymore, can be safely removed:\n".$data->realPath."\n(Use the --move-orphaned parameter to move it automatically.)\n\n";
				}
			}
		}
	}

	/**
	 * Indexes destination directory
	 */
	protected function indexLibrary()
	{
		echo "Indexing the library in ".$this->destDir."\n\n";

		$this->brokenLibraryFiles = [];
		$files = $this->scanDirectories($this->destDir, true);

		foreach ($files as list($dir, $file)) {
			$fullPath = $dir.DS.$file;

			if (!$json = $this->parseMTags($fullPath)) {
				echo "- Broken or empty file, skipping:\n".$fullPath."\n\n";
				continue;
			}

			if (!$libraryId = $this->getDurationId($json)) {
				echo "- No durations, skipping:\n".$fullPath."\n\n";
				continue;
			}

			if (isset($this->library[$libraryId])) {
				echo "- Possible duplicate, skipping:\n".$fullPath."\n\n";
				continue;
			}

			$this->library[$libraryId] = new LibraryItem($this, $libraryId, $fullPath, $json);

			$brokenFile = false;
			$needsConverting = false;

			for ($i = 0, $l = count($json); $i < $l; $i++) {
				$f = $json[$i];

				if (empty($f[static::PATH_KEY])) {
					echo ". No path, preparing to fix:\n".$fullPath."|".$i."\n\n";
					if (!in_array($libraryId, $this->brokenLibraryFiles)) {
						$this->brokenLibraryFiles[] = $libraryId;
					}
				} else {
					$realSrcFile = $f[static::PATH_KEY];
					$absPath = substr($realSrcFile, 0, 1) === '/';
					$realSrcFile = explode('|', $realSrcFile)[0];
					$realSrcFile = $absPath ? substr($realSrcFile, 1) : $dir.'/'.$realSrcFile;

					if (!$realpath = $this->truepath($realSrcFile)) {
						if (!$brokenFile) {
							echo ". Broken paths, preparing to fix:\n".$fullPath."\n";
							$this->brokenLibraryFiles[] = $libraryId;
							$brokenFile = true;
						}
					} else if ($this->convertPaths && (($absPath && $this->relativePaths) || (!$absPath && !$this->relativePaths))) {
						if (!$needsConverting) {
							echo ". Paths to be converted in:\n".$fullPath."\n";
							$this->brokenLibraryFiles[] = $libraryId;
							$needsConverting = true;
						}
					}
				}
			}

			if ($brokenFile || $needsConverting) {
				echo "\n";
			}
		}
	}

	/**
	 * Produces m-TAGS compatible snapshot of a directory
	 *
	 * @param $dir
	 * @param array $files
	 * @return array
	 */
	protected function analyzeDirectory($dir, array $files = [])
	{
		$cueFiles = $fileTags = [];

		foreach ($files as $file) {
			$ext = $this->ext($file);

			if ($ext === 'cue') {
				$contents = $this->utf8(file_get_contents($this->win.$dir.DS.$file));
				$refFiles = preg_match_all('/^\s*FILE\s+[\'"]?([^\'"]+)[\'"]?/m', $contents, $matches);
				if ($refFiles === 1 && !isset($cueFiles[$matches[1][0]]) && file_exists($this->win.$dir.DS.$matches[1][0])) {
					$cueFiles[$matches[1][0]] = [$file, $contents];
				}
			} else if (isset($this->mediaTypes[$ext])) {

				$data = $this->id3->analyze($dir.DS.$file);

				if (!isset($data['playtime_seconds']) && $ext !== 'iso.wv') {
					echo "Failed to calculate duration of ".$file.", skipping.\n";
					continue;
				}

				$tags = [];
				foreach ((array)$this->mediaTypes[$ext] as $tagType) {
					if (!empty($data['tags'][$tagType])) {
						$tags = $this->flattenTags($data['tags'][$tagType]);
						break;
					}
				}

				if (!empty($tags['CUESHEET'])) {
					// Overwrites .cue reference
					$cueFiles[$file] = [null, $this->utf8($tags['CUESHEET'])];
				}

				$tags = $this->fixMapping($ext, $tags);

				$fileTags[$file] = [
					'playtime_seconds' => isset($data['playtime_seconds']) ? $data['playtime_seconds'] : null,
					'tags' => $tags
				];
			}
		}

		$mediums = [];

		foreach ($fileTags as $file => $data) {
			if (isset($cueFiles[$file])) {

				$cueFile = $cueFiles[$file][0];
				$cueData = (new getid3_cue($this->id3))->readCueSheet($cueFiles[$file][1]);
				$cueTags = !empty($cueData['comments']) ? $this->flattenTags($cueData['comments']) : [];

				if (!empty($cueData['tracks'])) {
					$tracks = [];

					foreach ($cueData['tracks'] as $num => $track) {
						$trackEntry = [];

						if (!empty($track['performer'])) {
							$trackEntry['ARTIST'] = $track['performer'];
						}
						if (!empty($track['track_number'])) {
							$trackEntry['TRACKNUMBER'] = $track['track_number'];
						}
						if (!empty($track['title'])) {
							$trackEntry['TITLE'] = $track['title'];
						}

						$trackEntry = array_merge($cueTags, $data['tags'], $trackEntry);

						unset($trackEntry['CUESHEET']);

						$entry = [
							static::PATH_KEY => ($this->isWindows ? '/' : '').str_replace(DS, '/', $dir).'/'.($cueFile ?: $file).'|'.($num + 1)
						];

						if ($duration = $this->getCueTrackDuration($data['playtime_seconds'], $cueData['tracks'], $num)) {
							$entry[static::DURATION_KEY] = $duration;
						}

						$entry += $trackEntry;

						$tracks[] = $entry;
					}

					$key = count($cueFiles) > 1 ? ($cueFile ?: $file) : null;
					$key ? $mediums[$key] = $tracks : $mediums[] = $tracks;
				} else {
					continue;
				}


			} else {
				if (!$mediums) {
					$mediums[0] = [];
				}

				$mediums[0][] = [
						static::PATH_KEY => ($this->isWindows ? '/' : '').str_replace(DS, '/', $dir).'/'.$file,
						static::DURATION_KEY => $this->formatDuration($data['playtime_seconds'])
					] + $data['tags'];
			}
		}

		return $mediums;
	}

	/**
	 * @param string $ext
	 * @param array $tags
	 * @return array
	 */
	protected function fixMapping($ext, array $tags)
	{
		if (!empty($tags['ALBUM_ARTIST'])) {
			$tags['ALBUM ARTIST'] = $tags['ALBUM_ARTIST'];
			unset($tags['ALBUM_ARTIST']);
		}

		if (!empty($tags['TRACK_NUMBER'])) {
			$tags['TRACKNUMBER'] = $tags['TRACK_NUMBER'];
			unset($tags['TRACK_NUMBER']);
		}

		if (!empty($tags['DISC_NUMBER'])) {
			$tags['DISCNUMBER'] = $tags['DISC_NUMBER'];
			unset($tags['DISC_NUMBER']);
		}

		if (!empty($tags['TRACK'])) {
			switch ($ext) {
				case "mp3":
				case "flac":
					$tags['TRACKNUMBER'] = $tags['TRACK'];
					unset($tags['TRACK']);
			}
		}

		if (!empty($tags['YEAR'])) {
			switch ($ext) {
				case "mp3":
				case "flac":
					$tags['DATE'] = $tags['YEAR'];
					unset($tags['YEAR']);
			}
		}

		if (!empty($tags['STYLE'])) {
			$genre = !empty($tags['GENRE']) ? (array)$tags['GENRE'] : [];
			$tags['GENRE'] = array_unique(array_merge($genre, (array)$tags['STYLE']));
			unset($tags['STYLE']);
		}

		return $tags;
	}

	/**
	 * Adds a directory/.cue/file with CUESHEET tag to library
	 *
	 * @param string $libraryId
	 * @param string $path
	 * @param array $tags
	 * @return bool|LibraryItem
	 */
	protected function addToLibrary($libraryId, $path, array $tags)
	{
		$relPath = mb_substr($path, mb_strlen($this->srcDir) + 1);

		if (!$relPath) {
			$relPath = $this->basename($this->srcDir);
		}

		$tagFile = $this->destDir.DS.$relPath.'.'.static::EXT;

		$libraryItem = new LibraryItem($this, $libraryId, $tagFile, $tags);

		if ($libraryItem->save()) {
			return $libraryItem;
		} else {
			return false;
		}
	}

	/**
	 * @param $seconds
	 * @param $tracks
	 * @param int $num
	 * @return bool|string
	 */
	protected function getCueTrackDuration($seconds, array $tracks, $num)
	{
		if (empty($tracks[$num]['index'][1])) {
			return '';
		}

		$startFrame = $this->countFrames($tracks[$num]['index'][1]);

		if (isset($tracks[$num + 1]['index'][0])) {
			$endFrame = $this->countFrames($tracks[$num + 1]['index'][0]);
		} else if (isset($tracks[$num + 1]['index'][1])) {
			$endFrame = $this->countFrames($tracks[$num + 1]['index'][1]);
		} else {
			if (isset($seconds)) {
				$endFrame = ceil($seconds * 75);
			} else {
				// Dirty hack for iso.wv
				$endFrame = $startFrame + 75 * 180;
			}
		}

		$duration = $endFrame - $startFrame;
		$duration = $this->formatDuration($duration / 75);

		return $duration;
	}

	/**
	 * @param float $seconds
	 * @return string
	 */
	protected function formatDuration($seconds)
	{
		return number_format($seconds, 7, '.', '');
	}

	/**
	 * @param array $index
	 * @return int|float
	 */
	protected function countFrames(array $index)
	{
		$count = 0;

		if (!empty($index['frames'])) {
			$count += $index['frames'];
		}

		if (!empty($index['seconds'])) {
			$count += $index['seconds'] * 75;
		}

		if (!empty($index['minutes'])) {
			$count += $index['minutes'] * 60 * 75;
		}

		return $count;
	}

	/**
	 * @param array $tags
	 * @return array
	 */
	protected function flattenTags(array $tags = [])
	{
		$tags = array_map(function ($tag) {
			$tag = array_filter($tag, [$this, 'isNotEmptyString']);
			return $tag ? (count($tag) === 1 ? reset($tag) : $tag) : '';
		}, $tags);

		$tags = array_filter($tags, [$this, 'isNotEmptyString']);
		$tags = array_change_key_case($tags, CASE_UPPER);

		foreach ($tags as $key => $tag) {
			if (is_array($tag) && $tag && !is_int(array_keys($tag)[0])) {
				$tags += $tag;
				unset($tags[$key]);
			}
		}

		unset($tags['PICTURE']);

		array_walk_recursive($tags, function (&$tag) {
			$tag = $this->utf8(trim(trim($tag), '"'));
		});

		return $tags;
	}

	/**
	 * @param string $file Full file path
	 * @return array|bool
	 */
	protected function parseMTags($file)
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
		$keys = array_reduce($data, function ($result, $array) {
			if (isset($array[static::DURATION_KEY]) && $array[static::DURATION_KEY]) {
				$result[] = substr($array[static::DURATION_KEY], 0, -1);
			} else if (isset($array[static::PATH_KEY])) {
				echo "No duration found for ".$array[static::PATH_KEY]."\n";
			}
			return $result;
		}, []);

		return implode('|', $keys);
	}

	/**
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
		$fromPath = str_replace(['/', '\\'], DS, $fromPath);
		$toPath = str_replace(['/', '\\'], DS, $toPath);

		$fromWin = mb_substr($fromPath, 1, 1) === ':';
		$toWin = mb_substr($toPath, 1, 1) === ':';

		if ($fromWin && $toWin) {
			if (mb_strtolower(mb_substr($fromPath, 0, 2)) !== mb_strtolower(mb_substr($toPath, 0, 2))) {
				return $toPath;
			}

			if ($fromWin) {
				$fromPath = mb_split('[:]', $fromPath)[1];
			}

			if ($toWin) {
				$toPath = mb_split('[:]', $toPath)[1];
			}
		}

		$from = mb_split('['.preg_quote(DS).']', $fromPath); // Folders/File
		$to = mb_split('['.preg_quote(DS).']', $toPath); // Folders/File

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
			if (!empty($from[$j])) $relPath .= '..'.DS;
			$j--;
		}

		// Go to folder from where it starts differing
		while (isset($to[$i])) {
			if (!empty($to[$i])) $relPath .= $to[$i].DS;
			$i++;
		}

		// Strip last separator
		return mb_substr($relPath, 0, -1);
	}

	/**
	 * Recursively walks through directory tree and collects directories or .tags files
	 *
	 * @param string $rootDir
	 * @param bool $findTags
	 * @param array $result
	 * @return array
	 */
	protected function scanDirectories($rootDir, $findTags = false, $result = [])
	{
		if (!file_exists($this->win.$rootDir)) {
			echo "Directory not found: ".$rootDir."\n";
			return [];
		}

		$contents = scandir($this->win.$rootDir);
		$contents = array_diff($contents, ['..', '.']);
		$dirAdded = false;

		foreach ($contents as $file) {
			if (is_dir($this->win.$rootDir.DS.$file)) {
				$result = $this->scanDirectories($rootDir.DS.$file, $findTags, $result);
			} else {
				$ext = $this->ext($file);

				if ($findTags) {
					if ($ext === static::EXT) {
						$result[] = [$rootDir, $file];
					}
				} else if (!$dirAdded && isset($this->mediaTypes[$ext])) {
					$result[] = [$rootDir, $contents];
					$dirAdded = true;
				}
			}
		}

		return $result;
	}

	/**
	 * Unicode-safe basename()
	 *
	 * @param string $path
	 * @return string
	 */
	public function basename($path)
	{
		$path = preg_replace('/\\\\/', '/', $path);
		$path = rtrim($path, '/');
		$path = explode('/', $path);
		return end($path);
	}

	/**
	 * Unicode-safe dirname()
	 *
	 * @param string $path
	 * @return string
	 */
	public function dirname($path)
	{
		$path = preg_replace('/\\\\/', '/', $path);
		$path = rtrim($path, '/');
		$path = explode('/', $path);

		array_pop($path);

		$path = implode(DS, $path);

		return $path;
	}

	/**
	 * Extracts extension from unicode file name
	 *
	 * @param string $path
	 * @return string
	 */
	public function ext($path)
	{
		$ext = explode('.', $path);
		$ext = end($ext);

		if ($ext === 'wv' && mb_substr($path, -7) === '.iso.wv') {
			return 'iso.wv';
		}

		return $ext;
	}

	/**
	 * @param $string
	 * @param null $charlist
	 * @return mixed|string
	 */
	public function mb_trim($string, $charlist = null)
	{
		if (is_null($charlist)) {
			return trim($string);
		} else {
			$charlist = str_replace('/', "\/", preg_quote($charlist));
			return preg_replace("/(^[$charlist]+)|([$charlist]+$)/us", '', $string);
		}
	}

	/**
	 * @param string $str
	 * @return bool
	 */
	public function isNotEmptyString($str)
	{
		return $str !== '';
	}

	/**
	 * @param $path
	 * @return bool|string
	 */
	public function truepath($path)
	{
		if (!$this->isWindows) {
			return realpath($path);
		}

		// attempts to detect if path is relative in which case, add cwd
		if (!$this->isAbsolutePath($path)) {
			$path = wfio_getcwd8().DS.$path;
		}

		// resolve path parts (single dot, double dot and double delimiters)
		$path = str_replace(['/', '\\'], DS, $path);
		$path = preg_replace('/['.preg_quote(DS).']+/u', DS, $path);
		$parts = mb_split('['.preg_quote(DS).']', $path);

		$absolutes = [];
		foreach ($parts as $part) {
			if ('.' === $part) continue;
			if ('..' === $part) {
				array_pop($absolutes);
			} else {
				$absolutes[] = $part;
			}
		}

		$path = implode(DS, $absolutes);

		if (mb_strpos($path, ':') === false) {
			$path = mb_substr(wfio_getcwd8(), 0, 3).$path;
		}

		return file_exists($this->win.$path) ? $path : false;
	}

	/**
	 * @param $path
	 * @return bool
	 */
	public function isAbsolutePath($path)
	{
		return $this->isWindows ? mb_substr($path, 1, 1) === ':' : mb_substr($path, 0, 1) === '/';
	}

	/**
	 * Recursive mkdir
	 * In PHP <=5.6, mkdir creates directories recursively only one level deep.
	 *
	 * @param $path
	 * @param int $chmod
	 * @param bool|false $recursive
	 * @return bool
	 */
	public function mkdir($path, $chmod = 0755, $recursive = true)
	{
		$path = str_replace(['\\', '/'], DS, $path);

		if (!$recursive) {
			return mkdir($this->win.$path, $chmod);
		}

		$path = explode(DS, $path);
		$fullPath = '';

		if (strpos($path[0], ':') !== false) {
			$fullPath .= array_shift($path);
		}

		foreach ($path AS $p) {
			$fullPath .= DS.$p;
			if (!is_dir($this->win.$fullPath)) {
				if (!mkdir($this->win.$fullPath, $chmod, $recursive)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Ensures that a string is UTF-8 encoded
	 *
	 * @param $str
	 * @return bool|string
	 */
	public function utf8($str)
	{
		if (mb_check_encoding($str, 'UTF-8')) {
			return $str;
		}

		foreach ($this->nonUtf8Encodings as $enc) {
			if (mb_check_encoding($str, $enc)) {
				return mb_convert_encoding($str, 'UTF-8', $enc);
			}
		}

		return $str;
	}
}
