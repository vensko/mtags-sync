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

	public $emulation = false;
	public $verbose = false;

	/**
	 * @var array
	 */
	public $mediaTypes = [
		'flac' => 'vorbiscomment',
		'ogg' => 'vorbiscomment',
		'ape' => 'ape',
		'wv' => 'ape',
		'm4a' => 'quicktime',
		'mp3' => ['id3v1', 'id3v2']
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
				echo "php_wfio not loaded.\n\n";
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
		$this->emulation = !empty($args['emulation']) ? (bool)$args['emulation'] : false;
		$this->verbose = !empty($args['verbose']) ? (bool)$args['verbose'] : false;

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
--move-orphaned[=path]  Move orphaned .tags to a separate directory ({$orphanDir} in a source directory by default).
--verbose               Show even more messages.
--emulate               Don't do anything real.
--help                  Show this info.

TXT;
			exit;
		}

		$this->origSrcDir = $this->srcDir;
		$this->origDestDir = $this->destDir;

		if ($this->isWindows) {
			$this->srcDir = wfio_path2utf8($this->srcDir);
			$this->destDir = wfio_path2utf8($this->destDir);
		}

		$this->srcDir = $this->truepath($this->srcDir) ?: $this->srcDir;
		$this->destDir = $this->truepath($this->destDir) ?: $this->destDir;

		if (!$this->destDir || !is_dir($this->win.$this->destDir)) {
			if (!$this->mkdir($this->destDir, $this->dir_chmod, true)) {
				echo "Failed to create the destination directory. Create it manually.\n\n";
				exit;
			}
		}

		if (!$this->srcDir || !is_dir($this->win.$this->srcDir)) {
			echo "Source directory not found.\n\n";
			exit;
		}

		if ($this->orphanDir && is_bool($this->orphanDir)) {
			$this->orphanDir = $this->destDir.DS.static::ORPHAN_DIR;
		}

		if ($this->orphanDir && is_string($this->orphanDir) && !is_dir($this->win.$this->orphanDir)) {
			if (!$this->mkdir($this->orphanDir, $this->dir_chmod, true)) {
				echo "Failed to create directory for orphaned tags ".$this->orphanDir.", please, create it manually.\n\n";
				exit;
			}
		}

		$this->relativePaths = isset($args['no-relative']) ? !(bool)$args['no-relative'] : true;

		if ($this->isWindows) {
			$this->relativePaths = $this->relativePaths && (mb_strtolower(mb_substr($this->srcDir, 0, 2)) === mb_strtolower(mb_substr($this->destDir, 0, 2)));
		}
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
			$lines = shell_exec($command);

			if ($lines !== null) {
				$lines = trim($lines);
				preg_match_all('#^(.+)\|(.+)\|(.+\.([^.]+))$#m', $lines, $matches, PREG_SET_ORDER);
			}
		}

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

	/**
	 * Syncs destination directory with source
	 */
	public function sync()
	{
		$this->indexLibrary();

		echo "Indexing source files in ".$this->srcDir."\n\n";

		$ext = array_keys($this->mediaTypes);
		$ext[] = 'cue';

		$mediaFiles = $this->findFilesRecursively($this->origSrcDir, $ext);

		if (!$mediaFiles) {
			echo "No media files found.\n";
			return;
		}

		foreach ($mediaFiles as $dir => $contents) {
			if (!$mediums = $this->analyzeDirectory($dir, $contents)) {
				echo "- No candidates found in ".$dir.", skipping.\n\n";
				continue;
			}

			foreach ($mediums as $medium) {
				if (!$medium->exists) {
					echo "~ New release to add:\n".$medium->file."\n";
					if ($this->addToLibrary($medium)) {
						$this->library[$medium->file] = $medium;
						echo "+ Successfully saved.\n\n";
					}
				}
			}
		}

		$index = 0;
		foreach ($this->library as $file => $medium) {
			if (!$medium->exists) {
				if ($this->orphanDir) {
					echo "* Files don't exist anymore, moving to ".$this->orphanDir.":\n".$file."\n\n";
					rename($this->win.$file, $this->win.$this->orphanDir.DS.date('Y-d-m H-i-ss').'-'.++$index.' '.$this->basename($file));
				} else {
					echo "* Files don't exist anymore, can be safely removed:\n".$file."\n(Use the --move-orphaned parameter to move it automatically.)\n\n";
				}
			}
		}
	}

	protected function loadLibrary()
	{
		$files = $this->isWindows
			? 'cmd /u /c chcp 65001>nul && dir "'.$this->destDir.DS.'*.'.static::EXT.'" /B /S 2>nul'
			: 'find "'.$this->destDir.'" -type f -iname "*.'.static::EXT.'"';

		$files = shell_exec($files);

		if ($files === null) {
			echo "Unable to load the library\n\n";
			return;
		}

		if (mb_strpos($files, $this->destDir) === false) {
			return;
		}

		$this->library = [];
		$files = explode("\n", trim($files));

		foreach ($files as $file) {
			if (!$item = $this->loadLibraryItem($file)) {
				echo "- Empty or corrupted file, skipping:\n".$file."\n\n";
				continue;
			}

			foreach ($item->tags as $tag) {
				if (isset($tag[static::PATH_KEY])) {
					$i = mb_substr($tag[static::PATH_KEY], 0, 1);
					if ($i !== '/' && $i !== '.') {
						continue 2;
					}
				}
			}

			$this->library[$file] = $item;

			foreach ($item->getIdKeys() as $key) {
				$id = $item->getIndexId($key);
				if ($id && !isset($this->libraryIndex[$key][$id])) {
					$this->libraryIndex[$key][$id] = $item;
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
				echo "~ Invalid paths, preparing to fix:\n".$file."\n\n";
			} else {
				$medium->exists = true;
			}
		}
	}

	/**
	 * Produces m-TAGS compatible snapshot of a directory
	 *
	 * @param string $dir
	 * @param array $contents
	 * @return LibraryItem[]
	 */
	protected function analyzeDirectory($dir, array $contents = [])
	{
		if ($item = $this->getItemById(static::SIZE_KEY, $contents['sizes'], $dir, $contents['files'])) {
			if ($this->verbose) {
				echo "Matched by file sizes:\n".$dir."\n\n";
			}

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
				echo "getID3 error:\n".$file."\n";
				if (!empty($data['error'])) {
					echo implode("\n", $data['error']);
				}
				echo "\n";
				$contents['data'][$file] = $contents['tags'][$file] = [];
			} else {
				$contents['durations'][$file] = $data['playtime_seconds'] = !empty($data['playtime_seconds'])
					? $this->formatDuration($data['playtime_seconds'])
					: null;

				$tags = [];

				foreach ((array)$this->mediaTypes[$ext] as $tagType) {
					if (!empty($data['tags'][$tagType])) {
						$tags = $this->flattenTags($data['tags'][$tagType]);
						break;
					}
				}

				if ($tags) {
					if (!empty($tags['CUESHEET'])) {
						// Overwrites .cue reference
						$cueFiles[$file] = [null, static::utf8($tags['CUESHEET'])];
					}

					$tags = $this->fixMapping($ext, $tags);
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
				if ($this->verbose) {
					echo "Matched by md5 hashes:\n".$dir."\n\n";
				}

				return [$item];
			}

			$durations = array_filter(array_column($contents['data'], 'playtime_seconds'));
			if ($durations && count($durations) === $fileNum && $item = $this->getItemById(static::DURATION_KEY, $durations, $dir, $contents['files'])) {
				if ($this->verbose) {
					echo "Matched by duration:\n".$dir."\n\n";
				}

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
					if ($this->verbose) {
						$crit = $key === static::INDEX_KEY ? 'TOC' : 'duration';
						echo "Cue sheet matched by ".$crit.":\n".$dir.DS.($cueFile ?: $file)."\n\n";
					}

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
			echo "Unable to add a medium without file path\n\n";
			return false;
		}

		if (mb_strpos($item->file, $this->srcDir) === 0) {
			$relPath = mb_substr($item->file, mb_strlen($this->srcDir) + 1);

			if (!$relPath) {
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
				if ($item->setPaths($key, $dir, $valuesIndex) && $item->save()) {
					echo "+ Successfully saved.\n\n";
				} else {
					echo "- Hmm, nothing changed.\n\n";
				}
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
			$tag = trim(trim($tag), '"');
			$tag = static::utf8($tag);
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
			// preg_replace('/^[\pZ\pC]+|[\pZ\pC]+$/u', '', $string);
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
		return mb_substr($path, 1, 1) === ':' || mb_substr($path, 0, 1) === '/';
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
		if ($this->emulation) {
			return true;
		}

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
}
