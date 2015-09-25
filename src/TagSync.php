<?php

namespace TagSync;

use CommandLine, getid3_cue;

if (!defined('DS')) {
	define('DS', DIRECTORY_SEPARATOR);
}

/**
 * Class TagSync
 */
class TagSync
{
	const VERSION = '0.2a';
	const EXT = 'tags';
	const WIN_EXT = 'wfio';
	const WIN_WRAPPER = 'wfio://';
	const ORPHAN_DIR = '!DELETED';
	const PATH_KEY = '@';
	const TITLE_KEY = 'TITLE';
	const SIZE_KEY = '@SIZE';
	const MD5_KEY = '@MD5';
	const INDEX_KEY = '@INDEX';
	const FP_KEY = '@FP';
	const DURATION_KEY = 'DURATION';

	public $origSrcDir, $origDestDir, $srcDir, $destDir, $destFile, $orphanDir;
	public $relativePaths = false;
	public $convertPaths = false;
	public $dir_chmod = 0755;

	public $colored = false;
	public $emulation = false;
	public $verbose = null;

	public $args = [];

	public $currentTask = null;

	public static $tasks = [
		'info',
		'sync'
	];

	/**
	 * @var array
	 */
	public $mediaTypes = [
		'flac' => 'flac',
		'ogg' => 'ogg',
		'ape' => 'ape',
		'wv' => 'ape',
		'm4a' => 'quicktime',
		'mp3' => ['id3v2','id3v1']
	];

	/**
	 * @var array
	 */
	public static $nonUtf8Encodings = ['CP1251', 'CP1252', 'ISO-8859-1', 'ISO-8859-2', 'UCS-2', 'UTF-16BE', 'UTF-16LE'];

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
	 * @var LibraryItem[][]
	 */
	protected $libraryIndex = [];

	protected $cli = false;

	const LOG_INFO = 0;
	const LOG_SUCCESS = 1;
	const LOG_WARNING = 2;
	const LOG_DEBUG = 3;
	const LOG_ERROR = 4;
	const LOG_HALT = 5;

	const CONSOLE_BLACK = '1;30';
	const CONSOLE_RED = '1;31';
	const CONSOLE_GREEN = '1;32';
	const CONSOLE_YELLOW = '1;33';
	const CONSOLE_BLUE = '1;34';
	const CONSOLE_PURPLE = '1;35';
	const CONSOLE_CYAN = '1;36';
	const CONSOLE_GRAY = '0;37';
	const CONSOLE_WHITE = '1;37';

	public static $consoleColors = [
		self::LOG_INFO => self::CONSOLE_WHITE,
		self::LOG_WARNING => self::CONSOLE_YELLOW,
		self::LOG_SUCCESS => self::CONSOLE_GREEN,
		self::LOG_DEBUG => self::CONSOLE_CYAN,
		self::LOG_ERROR => self::CONSOLE_RED,
		self::LOG_HALT => self::CONSOLE_PURPLE
	];

	public static $verboseLevels = [
		self::LOG_SUCCESS => true,
		self::LOG_DEBUG => true
	];

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->cli = PHP_SAPI === 'cli';

		if (DS === '\\') {
			$this->isWindows = true;
			// https://github.com/kenjiuno/php-wfio
			if (extension_loaded(static::WIN_EXT)) {
				$this->win = static::WIN_WRAPPER;
			} else {
				$this->log("php_wfio not loaded.", self::LOG_HALT);
			}
		}

		getID3::$mtags = $this;

		$this->id3 = new getID3();
		$this->id3->option_tags_process = false;
		$this->id3->option_tags_html = false;
		$this->id3->option_save_attachments = false;
	}

	/**
	 * Parses user input
	 */
	public function parseArgs()
	{
		if (!$this->cli) {
			$this->log(__METHOD__.' can\'t be used in a SAPI other than cli.', static::LOG_HALT);
		}

		$this->args = CommandLine::parseArgs();
		$this->currentTask = !empty($this->args[0]) && in_array($this->args[0], static::$tasks) ? $this->args[0] : null;

		if (!$this->currentTask || isset($this->args['help'])) {
			$version = static::VERSION;
			$mtags = $this->isWindows ? 'mtags.bat' : 'php mtags.phar';

			echo <<<TXT
m-TAGS Sync {$version}

Usage:
{$mtags} info media-file [options]
{$mtags} sync source-directory destination-directory [options]

Options:
--verbose               Show even more messages.
--colored               Colored output.
--emulate               Don't do anything real.
--help                  Show this info.

Sync options:
--no-relative           Always write absolute paths.
--convert-paths         Convert existing paths, if they don't match the --no-relative option.
--move-orphaned[=path]  Move orphaned .tags to a separate directory.

TXT;
			exit;
		}

		$this->colored = CommandLine::getBoolean('colored', !$this->isWindows);
		$this->verbose = CommandLine::getBoolean('verbose', $this->verbose);
		$this->emulation = CommandLine::getBoolean('emulate', $this->emulation);

		return $this->currentTask;
	}

	/**
	 * @param $dir
	 * @param array $ext
	 * @return array
	 */
	public function findFilesRecursively($dir, array $ext = [])
	{
		$matches = $media = [];

		if ($this->isWindows) {
			if ($ext) {
				$ext = array_map(function ($ext) {
					return '*.'.$ext;
				}, $ext);
				$ext = implode(' ', $ext);
			} else {
				$ext = '*.*';
			}

			$command = 'cmd /u /c chcp 65001>nul && where /t /f /r "'.$dir.'" '.$ext.' 2>nul';
			$this->log('Executing shell command:', static::LOG_DEBUG, $command);
			$lines = shell_exec($command);

			if ($lines !== null) {
				$lines = trim($lines);
				preg_match_all('#^\s*(\d+).+"(.+)\x5c(.+\.([^.]+))"\s*$#m', $lines, $matches, PREG_SET_ORDER);
			}
		} else {
			if ($ext) {
				$ext = array_map(function ($ext) {
					return '-iname "*.'.$ext.'"';
				}, $ext);
				$ext = '\\( '.implode(' -o ', $ext).' \\)';
			} else {
				$ext = '';
			}

			$command = 'find "'.$dir.'" '.$ext.' -type f -printf "%s|%h|%f\n"';
			$this->log('Executing shell command:', static::LOG_DEBUG, $command);
			$lines = shell_exec($command);

			if ($lines !== null) {
				$lines = trim($lines);
				preg_match_all('#^(.+)\|(.+)\|(.+\.([^.]+))$#m', $lines, $matches, PREG_SET_ORDER);
			}
		}

		$this->log(count($matches).' files found', static::LOG_DEBUG);

		foreach ($matches as $match) {
			if (strcasecmp($match[4], 'cue') === 0) {
				$media[$match[2]]['cues'][] = $match[3];
			} else {
				$media[$match[2]]['files'][] = $match[3];
				$media[$match[2]]['info'][$match[3]] = [ctype_lower($match[4]) ? $match[4] : strtolower($match[4]), $match[1]];
				$media[$match[2]]['sizes'][] = $match[1];
			}
		}

		return $media;
	}

	public function analyze($mediaFile = null)
	{
		$this->id3->option_tag_lyrics3 = true;
		$this->id3->option_extra_info = true;

		if ($this->cli && !empty($this->args[1])) {
			$mediaFile = $this->args[1];

			if ($this->isWindows) {
				$mediaFile = wfio_path2utf8($mediaFile);
			}
		}

		if (!$mediaFile) {
			$this->log("Set media file!", self::LOG_HALT);
		}

		return $this->id3->analyze($mediaFile);
	}

	/**
	 * Syncs destination directory with source
	 */
	public function sync()
	{
		$this->id3->option_tag_lyrics3 = false;
		$this->id3->option_extra_info = false;

		if ($this->cli) {
			$args = $this->args;
			$this->srcDir = !empty($args[1]) ? $args[1] : null;
			$this->destDir = !empty($args[2]) ? $args[2] : null;
			$this->orphanDir = !empty($args['move-orphaned']) ? $args['move-orphaned'] : false;
			$this->convertPaths = CommandLine::getBoolean('convert-paths', $this->convertPaths);
			$this->relativePaths = !CommandLine::getBoolean('no-relative', $this->relativePaths);
		}

		$this->origSrcDir = $this->srcDir;
		$this->origDestDir = $this->destDir;

		if (!$this->srcDir) {
			$this->log("Set source directory!", self::LOG_HALT);
		}

		if (!$this->destDir) {
			$this->log("Set destination directory!", self::LOG_HALT);
		}

		if ($this->cli && $this->isWindows) {
			$this->srcDir = wfio_path2utf8($this->srcDir);
			$this->destDir = wfio_path2utf8($this->destDir);
		}

		$this->srcDir = $this->truepath($this->srcDir) ?: $this->srcDir;
		$this->destDir = $this->truepath($this->destDir) ?: $this->destDir;

		if (!$this->destDir || !is_dir($this->win.$this->destDir)) {
			if (!$this->mkdir($this->destDir, $this->dir_chmod, true)) {
				$this->log("Failed to create the destination directory. Create it manually.", self::LOG_HALT);
			}
		}

		if (!$this->srcDir || !is_dir($this->win.$this->srcDir)) {
			$this->log("Source directory not found.", self::LOG_HALT);
		}

		if ($this->orphanDir && is_bool($this->orphanDir)) {
			$this->orphanDir = $this->destDir.DS.static::ORPHAN_DIR;
		}

		if ($this->orphanDir && is_string($this->orphanDir) && !is_dir($this->win.$this->orphanDir)) {
			if (!$this->mkdir($this->orphanDir, $this->dir_chmod, true)) {
				$this->log("Failed to create directory for orphaned tags ".$this->orphanDir.", please, create it manually.", self::LOG_HALT);
			}
		}

		if ($this->isWindows) {
			$this->relativePaths = $this->relativePaths && strtolower($this->srcDir[0]) === strtolower($this->destDir[0]);
		}

		$this->indexLibrary();

		$this->log("Indexing source files in ".$this->srcDir);

		$ext = array_keys($this->mediaTypes);
		$ext[] = 'cue';

		$mediaFiles = $this->findFilesRecursively($this->origSrcDir, $ext);

		if (!$mediaFiles) {
			$this->log("No media files found.", self::LOG_HALT);
		}

		foreach ($mediaFiles as $dir => $contents) {
			if (!$mediums = $this->analyzeDirectory($dir, $contents)) {
				$this->log("No candidates found, skipping.", self::LOG_DEBUG, $dir);
				continue;
			}

			foreach ($mediums as $medium) {
				if (!$medium->exists) {
					if ($this->addToLibrary($medium)) {
						$this->library[$medium->file] = $medium;
						$this->log("Added: ".$medium->file, self::LOG_INFO, null, "\n\n", self::CONSOLE_GREEN);
					}
				}
			}
		}

		$index = 0;
		foreach ($this->library as $file => $medium) {
			if (!$medium->exists) {
				if ($this->orphanDir) {
					$this->log("Files don't exist anymore, moving to ".$this->orphanDir.".", self::LOG_WARNING, $file);
					rename($this->win.$file, $this->win.$this->orphanDir.DS.date('Y-d-m H-i-ss').'-'.++$index.' '.$this->basename($file));
				} else {
					$this->log("Files don't exist anymore, can be safely removed:", self::LOG_WARNING, $file);
				}
			}
		}

		$this->resetConsoleColor();

		echo "Synced.\n";
	}

	/**
	 * If colored output is enabled, resets console colors
	 */
	public function resetConsoleColor()
	{
		if ($this->colored) {
			echo "\033[0m";
		}
	}

	/**
	 * Recursively loads .tags and indexes them
	 */
	protected function loadLibrary()
	{
		$files = $this->isWindows
			? 'cmd /u /c chcp 65001>nul && dir "'.$this->destDir.DS.'*.'.static::EXT.'" /B /S 2>nul'
			: 'find "'.$this->destDir.'" -type f -iname "*.'.static::EXT.'"';

		$this->log('Executing shell command:', static::LOG_DEBUG, $files);

		$files = shell_exec($files);

		if (!$files || mb_strpos($files, $this->destDir) === false) {
			$this->log("Library is empty?", self::LOG_WARNING);
			return;
		}

		$this->library = [];
		$files = explode("\n", trim($files));

		$this->log(count($files).' files found:', static::LOG_DEBUG, null, "\n");

		foreach ($files as $file) {
			if (!$item = $this->loadLibraryItem($file)) {
				$this->log("Empty or corrupted file, skipping:", self::LOG_ERROR, $file);
				continue;
			}

			foreach ($item->tags as $tag) {
				if (!empty($tag[static::PATH_KEY])) {
					$i = $tag[static::PATH_KEY][0];
					if ($i !== '/' && $i !== '.') {
						continue 2;
					}
				}
			}

			$this->log($item->file, self::LOG_DEBUG, null, "\n");

			$this->library[$file] = $item;

			foreach ($item->getIdKeys() as $key) {
				$id = $item->getIndexId($key);
				if ($id && !isset($this->libraryIndex[$key][$id])) {
					$this->libraryIndex[$key][$id] = $item;
				}
			}
		}

		$this->log("", self::LOG_DEBUG, null, "\n");
	}

	/**
	 * Indexes destination directory
	 */
	protected function indexLibrary()
	{
		$this->log("Indexing library in ".$this->destDir);

		$this->loadLibrary();

		foreach ($this->library as $file => $medium) {
			foreach ($medium->tags as $tag) {
				$srcFile = empty($tag[static::PATH_KEY]) ? false : explode('|', $tag[static::PATH_KEY])[0];
				$absPath = $srcFile && $this->isAbsolutePath($srcFile);
				$exists = $srcFile && $this->truepath($srcFile, $this->dirname($file));
				$convertPaths = $exists && $this->convertPaths && (($absPath && $this->relativePaths) || (!$absPath && !$this->relativePaths));

				if (!$srcFile || !$exists || $convertPaths) {
					$medium->broken = true;
					break;
				}
			}
			if ($medium->broken) {
				$this->log("Invalid paths, queued for fix.", self::LOG_WARNING, $file);
			} else {
				$medium->exists = true;
			}
		}
	}

	/**
	 * Matches releases and prepares new ones
	 *
	 * @param string $dir
	 * @param array $contents
	 * @return LibraryItem[]
	 */
	protected function analyzeDirectory($dir, array $contents = [])
	{
		if (!isset($contents['sizes'])) {
			return [];
		}

		if ($item = $this->getItemById(static::SIZE_KEY, $contents['sizes'], $dir, $contents['files'])) {
			$this->log("Matched by file sizes:", self::LOG_DEBUG, $dir);
			return [$item];
		}

		$contents['tags'] = $contents['data'] = $contents['durations'] = [];

		$cueFiles = [];
		if (isset($contents['cues'])) {
			foreach ($contents['cues'] as $cueFile) {
				$cueContent = static::utf8(file_get_contents($this->win.$dir.DS.$cueFile));
				$refFiles = preg_match_all('/^\s*FILE\s+[\'"]?([^\'"]+)[\'"]?/m', $cueContent, $matches);
				if ($refFiles === 1 && !isset($cueFiles[$matches[1][0]]) && isset($contents['info'][$matches[1][0]])) {
					$cueFiles[$matches[1][0]] = [$cueFile, $cueContent];
				}
			}
		}

		foreach ($contents['info'] as $file => list($ext, $size)) {
			$data = $this->id3->analyze($dir.DS.$file, $size);

			if (!$data || isset($data['error'])) {
				$this->log("getID3 error:", self::LOG_ERROR, $file, "\n");
				if (!empty($data['error'])) {
					$this->log(implode("\n", $data['error']), self::LOG_WARNING, null, "\n");
				}
				$this->log("\n", self::LOG_ERROR);
				$contents['data'][$file] = $contents['tags'][$file] = [];
			} else {
				$contents['durations'][$file] = $data['playtime_seconds'] = !empty($data['playtime_seconds'])
					? $this->formatDuration($data['playtime_seconds'])
					: null;

				$tags = [];

				foreach ((array)$this->mediaTypes[$ext] as $tagType) {
					if (!empty($data[$tagType]['comments'])) {
						$encoding = isset($data[$tagType]['encoding']) ? $data[$tagType]['encoding'] : null;
						$tags = $this->flattenTags($data[$tagType]['comments'], $encoding);
						break;
					}
				}

				if ($tags) {
					if (!empty($tags['CUESHEET'])) {
						// Overwrites .cue reference
						$cueFiles[$file] = [null, static::utf8($tags['CUESHEET'])];
					}

					$tags = $this->fixMapping($ext, $tags);
				} else {
					$this->log("No tags found.", self::LOG_DEBUG, $file);
				}

				$contents['tags'][$file] = $tags;

				if (!isset($cueFiles[$file])) {
					$contents['data'][$file] = $data;
				}
			}
		}

		$mediums = [];
		$fileNum = count($contents['data']);

		if ($fileNum) {
			$md5ids = array_filter(array_column($contents['data'], 'md5_data_source'));
			if ($md5ids && count($md5ids) === $fileNum && $item = $this->getItemById(static::MD5_KEY, $md5ids, $dir, $contents['files'])) {
				$this->log("Matched by md5 hashes:", self::LOG_DEBUG, $dir);
				return [$item];
			}

			$durations = array_filter(array_column($contents['data'], 'playtime_seconds'));
			if ($durations && count($durations) === $fileNum && $item = $this->getItemById(static::DURATION_KEY, $durations, $dir, $contents['files'])) {
				$this->log("Matched by duration:", self::LOG_DEBUG, $dir);
				return [$item];
			}

			$tracks = [];
			foreach ($contents['data'] as $file => $data) {
				$track = [
					static::PATH_KEY => ($this->isWindows ? '/' : '').str_replace(DS, '/', $dir).'/'.$file
				];

				if (!empty($contents['info'][$file][1])) {
					$track[static::SIZE_KEY] = $contents['info'][$file][1];
				}

				if (!empty($data['playtime_seconds'])) {
					$track[static::DURATION_KEY] = $data['playtime_seconds'];
				}

				if (!empty($data['md5_data_source'])) {
					$track[static::MD5_KEY] = $data['md5_data_source'];
				}

				$track += $contents['tags'][$file];
				$tracks[] = $track;
			}

			$mediums[] = new LibraryItem($this, $tracks, $dir);
		}

		foreach ($cueFiles as $file => list($cueFile, $cueContent)) {

			$cueData = (new getid3_cue($this->id3))->readCueSheet($cueFiles[$file][1]);

			if (empty($cueData['tracks'])) {
				continue;
			}

			$cueTags = !empty($cueData['comments']) ? $this->flattenTags($cueData['comments']) : [];

			$tracks = $basenames = [];

			foreach ($cueData['tracks'] as $num => $track) {
				$basenames[] = $basename = ($cueFile ?: $file).'|'.($num + 1);

				$cueTrack = [
						static::PATH_KEY => ($this->isWindows ? '/' : '').str_replace(DS, '/', $dir).'/'.$basename
					] + array_merge($cueTags, $contents['tags'][$file], $this->getCueTrackTags($track));

				$cueTrack = $this->fixMapping('cue', $cueTrack);

				if ($indexKey = $this->getIndexKey($track, $num)) {
					$cueTrack[static::INDEX_KEY] = $indexKey;
				}

				if ($duration = $this->getCueTrackDuration($contents['durations'][$file], $cueData['tracks'], $num)) {
					$cueTrack[static::DURATION_KEY] = $duration;
				}

				$tracks[] = $cueTrack;
			}

			$trackNum = count($cueData['tracks']);
			$found = false;

			foreach ([static::INDEX_KEY, static::DURATION_KEY] as $key) {
				$id = array_column($tracks, $key);
				if ($id && count($id) === $trackNum && $item = $this->getItemById($key, $id, $dir, $basenames)) {
					$this->log("Cue sheet matched by ".($key === static::INDEX_KEY ? 'TOC' : 'duration'), self::LOG_DEBUG, $dir.DS.($cueFile ?: $file));
					$mediums[] = $item;
					$found = true;
					break;
				}
			}

			if (!$found) {
				$tagsPath = count($cueFiles) > 1 ? $dir.DS.($cueFile ?: $file) : $dir;
				$mediums[] = new LibraryItem($this, $tracks, $tagsPath);
			}
		}

		return $mediums;
	}

	/**
	 * Adds a directory/.cue/file with CUESHEET tag to library
	 *
	 * @param LibraryItem $item
	 * @return LibraryItem|bool
	 */
	protected function addToLibrary(LibraryItem $item)
	{
		if (!$item->file) {
			$this->log("Unable to add a medium without file path.", self::LOG_ERROR);
			return false;
		}

		if (mb_strpos($item->file, $this->srcDir) === 0) {
			$relPath = mb_substr($item->file, mb_strlen($this->srcDir) + 1);

			if (mb_strlen($relPath) <= strlen(static::EXT)) {
				$relPath = $this->basename($this->srcDir).'.'.static::EXT;
			}

			$item->file = $this->destDir.DS.$relPath;
		}

		return $item->save() ? $item : false;
	}

	/**
	 * @param $key
	 * @param array $values
	 * @param $dir
	 * @param $files
	 * @return LibraryItem|null
	 */
	protected function getItemById($key, array $values, $dir, $files)
	{
		if (!$values || !isset($this->libraryIndex[$key])) {
			return null;
		}

		$index = $this->libraryIndex[$key];
		$valuesIndex = array_combine($files, $values);

		sort($values);
		$id = md5(implode('|', $values));

		if ($id && isset($index[$id])) {
			$item = $index[$id];

			if ($item->broken) {
				($item->setPaths($key, $dir, $valuesIndex) && $item->save())
					? $this->log("Successfully saved.", self::LOG_SUCCESS)
					: $this->log("Hmm, nothing changed.", self::LOG_WARNING);
			}

			$item->exists = true;
			return $item;
		}

		return null;
	}

	/**
	 * @param string $ext
	 * @param array $tags
	 * @return array
	 */
	protected function fixMapping($ext, array $tags)
	{
		if ($ext === 'cue') {
			unset($tags['CUESHEET']);
		}

		if (!empty($tags['ALBUM_ARTIST'])) {
			$tags['ALBUM ARTIST'] = $tags['ALBUM_ARTIST'];
			unset($tags['ALBUM_ARTIST']);
		}

		if (!empty($tags['ALBUMARTIST'])) {
			$tags['ALBUM ARTIST'] = $tags['ALBUMARTIST'];
			unset($tags['ALBUMARTIST']);
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
					if (empty($tags['DATE'])) {
						$tags['DATE'] = $tags['YEAR'];
						unset($tags['YEAR']);
					}
			}
		}

		if (!empty($tags['STYLE'])) {
			$genre = !empty($tags['GENRE']) ? (array)$tags['GENRE'] : [];
			$tags['GENRE'] = array_unique(array_merge($genre, (array)$tags['STYLE']));
			unset($tags['STYLE']);
		}

		if (isset($tags['TRACKNUMBER'])) {
			$track = str_pad($tags['TRACKNUMBER'], 2, '0', STR_PAD_LEFT);
			if (isset($tags['CUE_TRACK'.$track.'_LYRICS'])) {
				$tags['LYRICS'] = $tags['CUE_TRACK'.$track.'_LYRICS'];
				foreach (array_keys($tags) as $key) {
					if (strpos($key, '_LYRICS') !== false) {
						unset($tags[$key]);
					}
				}
			}
		}

		return $tags;
	}

	protected function getCueTrackTags($track)
	{
		$tags = [];

		if (!empty($track['performer'])) {
			$tags['ARTIST'] = $track['performer'];
		}
		if (!empty($track['track_number'])) {
			$tags['TRACKNUMBER'] = $track['track_number'];
		}
		if (!empty($track['title'])) {
			$tags['TITLE'] = $track['title'];
		}
		if (isset($track['isrc']) && trim($track['isrc'], '0')) {
			$tags['ISRC'] = $track['isrc'];
		}

		return $tags;
	}

	/**
	 * @param $seconds
	 * @param $tracks
	 * @param int $num
	 * @return bool|string
	 */
	protected function getCueTrackDuration($seconds, array $tracks, $num)
	{
		if (!$seconds || empty($tracks[$num]['index'][1])) {
			return '';
		}

		$startFrame = $this->countFrames($tracks[$num]['index'][1]);

		if (isset($tracks[$num + 1]['index'][0])) {
			$endFrame = $this->countFrames($tracks[$num + 1]['index'][0]);
		} else if (isset($tracks[$num + 1]['index'][1])) {
			$endFrame = $this->countFrames($tracks[$num + 1]['index'][1]);
		} else {
			$endFrame = ceil($seconds * 75);
		}

		$duration = $endFrame - $startFrame;
		$duration = $this->formatDuration($duration / 75);

		return $duration;
	}

	protected function getIndexKey($track, $num)
	{
		$key = [];

		if ($num !== 0 && !trim(implode('', $track['index'][1]), '0')) {
			return $key;
		}

		if (isset($track['index'][0])) {
			$key[] = implode(':', $track['index'][0]);
		}

		if (isset($track['index'][1])) {
			$key[] = implode(':', $track['index'][1]);
		}

		return implode(';', $key);
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
	 * @param null $encoding
	 * @return array
	 */
	protected function flattenTags(array $tags = [], $encoding = null)
	{
		$tags = array_map(function ($tag) {
			$tag = array_filter($tag, [$this, 'isInfoTag']);
			return $tag ? (count($tag) === 1 ? reset($tag) : $tag) : '';
		}, $tags);

		$tags = array_filter($tags, [$this, 'isInfoTag']);
		$tags = array_change_key_case($tags, CASE_UPPER);

		foreach ($tags as $key => $tag) {
			if (is_array($tag) && $tag && !is_int(array_keys($tag)[0])) {
				$tags += $tag;
				unset($tags[$key]);
			}
		}

		unset($tags['PICTURE']);

		array_walk_recursive($tags, function (&$tag) use ($encoding) {
			$tag = trim(trim($tag), '"');
			if ($encoding !== 'UTF-8' || !mb_check_encoding($tag, 'UTF-8')) {
				$tag = static::utf8($tag);
			}
		});

		return $tags;
	}

	/**
	 * @param string $file Full file path
	 * @return LibraryItem
	 */
	protected function loadLibraryItem($file)
	{
		$json = file_get_contents($this->win.$file, FILE_TEXT);

		if (!$json) {
			return false;
		}

		if (substr($json, 0, 3) == pack('CCC', 239, 187, 191)) {
			$json = substr($json, 3);
		}

		$json = json_decode($json, true);

		if (!is_array($json)) {
			return false;
		}

		return new LibraryItem($this, $json, $file);
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
		$ds = strpos($fromPath, '/') === false ? '\\' : '/';

		$fromPath = str_replace(['/', '\\'], $ds, $fromPath);
		$toPath = str_replace(['/', '\\'], $ds, $toPath);

		$fromWin = $fromPath[1] === ':';
		$toWin = $toPath[1] === ':';

		if ($fromWin && $toWin) {
			if (strtolower($fromPath[0]) !== strtolower($toPath[0])) {
				return $toPath;
			}

			if ($fromWin) {
				$fromPath = explode(':', $fromPath)[1];
			}

			if ($toWin) {
				$toPath = explode(':', $toPath)[1];
			}
		}

		$from = explode($ds, $fromPath); // Folders/File
		$to = explode($ds, $toPath); // Folders/File

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
			if (!empty($from[$j])) $relPath .= '..'.$ds;
			$j--;
		}

		// Go to folder from where it starts differing
		while (isset($to[$i])) {
			if (!empty($to[$i])) $relPath .= $to[$i].$ds;
			$i++;
		}

		// Strip last separator
		return rtrim($relPath, $ds);
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
	 * @param string $str
	 * @return bool
	 */
	public function isInfoTag($str)
	{
		return $str !== '' && !isset($str['data']);
	}

	/**
	 * @param $path
	 * @param null $cwd
	 * @return bool|string
	 */
	public function truepath($path, $cwd = null)
	{
		$cwd = $cwd ?: ($this->isWindows ? wfio_getcwd8() : getcwd());
		$ds = strpos($cwd, '/') === false ? '\\' : '/';

		// attempts to detect if path is relative in which case, add cwd
		if (!$this->isAbsolutePath($path)) {
			$path = $cwd.$ds.$path;
		}

		$path = preg_replace('#[/\\\\]+#', $ds, $path);
		$path = ltrim($path, $ds);
		$parts = explode($ds, $path);

		$absolutes = [];
		foreach ($parts as $part) {
			if ('.' === $part) continue;
			if ('..' === $part) {
				array_pop($absolutes);
			} else {
				$absolutes[] = $part;
			}
		}

		$path = implode($ds, $absolutes);

		if ($ds === '/') {
			$path = '/'.$path;
		}

		return file_exists($this->win.$path) ? $path : false;
	}

	/**
	 * @param $path
	 * @return bool
	 */
	public function isAbsolutePath($path)
	{
		return $path[0] === '/' || $path[1] === ':';
	}

	/**
	 * Recursive mkdir
	 *
	 * @param $path
	 * @param int $chmod
	 * @param bool|false $recursive
	 * @return bool
	 */
	public function mkdir($path, $chmod = 0755, $recursive = true)
	{
		if ($this->emulation) {
			return true;
		}

		if (!$this->isWindows) {
			return mkdir(str_replace('\\', '/', $path), $chmod, $recursive);
		}

		$path = str_replace('/', DS, $path);

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
	public static function utf8($str)
	{
		if (mb_check_encoding($str, 'UTF-8')) {
			return $str;
		}

		foreach (static::$nonUtf8Encodings as $enc) {
			if (mb_check_encoding($str, $enc)) {
				return mb_convert_encoding($str, 'UTF-8', $enc);
			}
		}

		return $str;
	}

	/**
	 * @param $str
	 * @param int $level
	 * @param null $subject
	 * @param string $lineBreak
	 * @param null $color
	 */
	public function log($str, $level = self::LOG_INFO, $subject = null, $lineBreak = "\n\n", $color = null)
	{
		if ($this->verbose === false) {
			return;
		}

		if (!isset(static::$verboseLevels[$level]) || $this->verbose) {
			echo $this->coloredOutput($str, $color ?: static::$consoleColors[$level]).($subject ? "\n" : '').$subject.$lineBreak;
		}

		if ($level === self::LOG_HALT) {
			exit;
		}
	}

	/**
	 * @param string $str
	 * @param null $color
	 * @return string
	 */
	protected function coloredOutput($str, $color = null)
	{
		return ($this->colored && $color !== null) ? "\033[".$color."m".$str."\033[37m" : $str;
	}
}
