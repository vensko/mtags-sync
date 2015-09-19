<?php

error_reporting(-1);
define('DS', DIRECTORY_SEPARATOR);

$tagSync = new TagSync();
$tagSync->parseArgs();

switch ($tagSync->getTask()) {
	case 'sync':
		$tagSync->sync();
		break;
	case 'import':
		$tagSync->import();
		break;
}

echo "Done.\n";

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
	const FOLDERBROWSE_EXE = 'folderbrowse.exe';
	const DIR_TAGS = '!';

	const PATH_KEY = '@';
	const DURATION_KEY = 'DURATION';

	public $srcDir, $destDir, $orphanDir;

	public $relativePaths = false;
	public $convertPaths = false;
	public $dirTags = '!';
	public $dir_chmod = 0755;
	public $mediaExtensions = ['flac', 'mp3', 'm4a'];
	public $isWindows = false;
	public $win = '';

	protected $id3;

	/**
	 * @var LibraryItem[]
	 */
	protected $library = [];

	protected $tasks = ['sync'];
	protected $currentTask = '';

	protected $brokenLibraryFiles = [];

	/**
	 * Parses command lines parameters
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

		require_once(__DIR__.'/getID3/getid3/getid3.php');

		$this->id3 = new getID3;
		$this->id3->win = $this->win;
	}

	public function parseArgs()
	{
		$args = CommandLine::parseArgs();
		$chdir = $this->isWindows ? wfio_getcwd8() : getcwd();

		$this->currentTask = isset($args[0]) ? $args[0] : null;
		$this->srcDir = !empty($args[1]) ? $args[1] : null;
		$this->destDir = !empty($args[2]) ? $args[2] : null;
		$this->dirTags = !empty($args['dir-tags']) ? $args['dir-tags'] : static::DIR_TAGS;
		$this->orphanDir = !empty($args['move-orphaned']) ? $args['move-orphaned'] : false;
		$this->convertPaths = !empty($args['convert-paths']) ? (bool)$args['convert-paths'] : false;

		$folderBrowse = false;

		if ($this->isWindows && !$this->currentTask && (!$this->srcDir || !$this->destDir) && file_exists(__DIR__.DS.static::FOLDERBROWSE_EXE)) {
			$folderBrowse = true;
			$initialDir = mb_substr($chdir, 0, 2);
			if (!$this->srcDir) {
				$this->srcDir = $this->mb_trim(trim(shell_exec(static::FOLDERBROWSE_EXE.' "Choose source directory:" '.$initialDir)), DS);
			}
			if (!$this->destDir) {
				$this->destDir = $this->mb_trim(trim(shell_exec(static::FOLDERBROWSE_EXE.' "Choose destination directory:" '.$initialDir)), DS);
			}
		}

		if (!$this->currentTask || $this->currentTask === 'help' || !$this->srcDir || ($folderBrowse && !$this->destDir) || isset($args['help']) || !in_array($this->currentTask, ['help','sync','import'])) {
			$orphanDir = static::ORPHAN_DIR;
			$version = static::VERSION;
			echo <<<TXT
m-TAGS Sync {$version}

Usage:
mtags import|sync source-directory [destination-directory] [options]

If destination directory is not set, the working directory will be used.

Tasks:
import    Copies all .tags from source directory, renaming them to parent directory names.
          Uses the --dir-tags parameter.
sync      Keeps the directory with .tags up to date.

Options:
--no-relative           Always write absolute paths.
--convert-paths         Convert existing paths, if they don't match the --no-relative option.
--move-orphaned[=path]  Move orphaned .tags to a separate directory ({$orphanDir} in a source directory by default)
--dir-tags=[name]       Common name of folder-wide .tags (! by default)
--help                  Shows this info.
TXT;

			if ($folderBrowse) {
				passthru('pause');
			}

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
				echo "Could not create the destination directory. Create it manually.";
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
				echo "Could not create directory for orphaned tags ".$this->orphanDir.", please, create it manually.";
				exit;
			}
		}

		$useRelative = isset($args['no-relative']) ? !(bool)$args['no-relative'] : true;

		$this->relativePaths = (
			$useRelative
			&& (
				!$this->isWindows
				|| (
					$this->isWindows
					&& (mb_strtolower(mb_substr($this->srcDir, 0, 2)) === mb_strtolower(mb_substr($this->destDir, 0, 2)))
				)
			)
		);
	}

	/**
	 * @return string
	 */
	public function getTask()
	{
		return $this->currentTask;
	}

	/**
	 * Imports existing .tags
	 */
	public function import()
	{
		$files = $this->scanDirectories($this->srcDir, true);

		foreach ($files as $file) {
			$relPath = mb_substr($file, mb_strlen($this->srcDir) + 1);
			$basename = $this->mb_basename($file, '.'.static::EXT);

			if ($basename === $this->dirTags) {
				$relPath = $this->mb_dirname($relPath);
				if (!$relPath) {
					$relPath = $this->mb_basename($this->srcDir);
				}
				$relPath .= '.'.static::EXT;
			}

			$tagFile = $this->destDir.DS.$relPath;

			if (file_exists($this->win.$tagFile)) {
				echo $tagFile." already exists, skipping.\n\n";
				continue;
			} else if (!rename($this->win.$file, $this->win.$tagFile)) {
				echo "Couldn't rename:\n".$file."\n-> ".$tagFile."\n\n";
			}
		}

		$this->sync();
	}

	/**
	 * Syncs destination directory with source
	 */
	public function sync()
	{
		$this->indexLibrary();

		echo "Indexing source files ".$this->srcDir."\n\n";

		$dirs = $this->scanDirectories($this->srcDir, false);

		if (!$dirs) {
			echo "No source directories found.\n";
			return;
		}

		foreach ($dirs as $dir) {
			if (!$tags = $this->analyzeDirectory($dir)) {
				echo "No candidates found in ".$dir[0].", skipping.\n\n";
				continue;
			}

			if (!$libraryId = $this->getDurationId($tags)) {
				echo "- No durations, skipping:\n".$dir[0]."\n\n";
				continue;
			}

			if (isset($this->library[$libraryId]) && !in_array($libraryId, $this->brokenLibraryFiles)) {
				$this->library[$libraryId]->exists = true;
				continue;
			}

			if (isset($this->library[$libraryId])) {
				$libraryItem = $this->library[$libraryId];
				echo ". Found directory to fix file paths in:\n".$libraryItem->realPath."\n-> ".$dir[0]."\n";
				if ($this->library[$libraryId]->setPaths($tags) && $this->library[$libraryId]->save()) {
					echo "+ Successfully saved.\n\n";
				} else {
					echo "- Hmm, nothing changed.\n\n";
				}
			} else {
				echo ". New release to add:\n".$dir[0]."\n";
				if ($libraryItem = $this->addToLibrary($libraryId, $dir[0], $tags)) {
					$this->library[$libraryId] = $libraryItem;
					echo "+ Successfully saved.\n\n";
				}
			}
		}

		$index = 0;
		foreach ($this->library as $id => $data) {
			if (!$data->exists) {
				if ($this->orphanDir) {
					echo "* Files don't exist anymore, moving to ".$this->mb_basename($this->orphanDir).":\n".$data->realPath."\n\n";
					rename($this->win.$this->library[$id]->realPath, $this->win.$this->orphanDir.DS.date('Y-d-m H-i-ss').'-'.++$index.' '.$this->mb_basename($this->library[$id]->realPath));
				} else {
					echo "* Files don't exist anymore, can be safely removed:\n".$data->realPath."\n(Use the --move-orphaned parameter to move it automatically.)\n\n";
				}
			}
		}
	}

	/**
	 * @param $dir
	 * @return array
	 */
	protected function analyzeDirectory($dir)
	{
		if (is_string($dir)) {
			if (!file_exists($this->win.$dir)) {
				echo "Directory not found: ".$dir;
				return [];
			}
			$files = scandir($this->win.$dir);
			$files = array_diff($files, ['..', '.']);
		} else {
			$files = $dir[1];
			$dir = $dir[0];
		}

		$results = [];

		foreach ($files as $file) {
			$ext = $this->mb_ext($file);

			if (!in_array($ext, $this->mediaExtensions)) {
				continue;
			}

			$data = $this->id3->analyze($dir.DS.$file);

			if (!$data) {
				continue;
			}

			if (!isset($data['playtime_seconds'])) {
				echo "Couldn't determine duration of ".$file.", skipping.\n";
				continue;
			}

			$entry = [
				static::PATH_KEY => ($this->isWindows ? '/' : '').str_replace(DS, '/', $dir).'/'.$file,
				static::DURATION_KEY => number_format($data['playtime_seconds'], 7, '.', '')
			];

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

				$entry += $tags;
			}

			$results[] = $entry;
		}

		return $results;
	}

	/**
	 * @param string $libraryId
	 * @param string $path
	 * @param array $tags
	 * @return bool|LibraryItem
	 */
	protected function addToLibrary($libraryId, $path, array $tags)
	{
		$relPath = mb_substr($path, mb_strlen($this->srcDir) + 1);

		if (!$relPath) {
			$relPath = $this->mb_basename($this->srcDir);
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
	 * @param string $file Real file path
	 * @return array|bool
	 */
	protected function parseTags($file)
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
	 * Indexes destination directory
	 */
	protected function indexLibrary()
	{
		echo "Indexing the library ".$this->destDir."\n\n";

		$this->brokenLibraryFiles = [];
		$files = $this->scanDirectories($this->destDir, true);

		foreach ($files as $fileName) {
			if (!$json = $this->parseTags($fileName)) {
				echo "- Broken or empty file, skipping:\n".$fileName."\n\n";
				continue;
			}

			if (!$libraryId = $this->getDurationId($json)) {
				echo "- No durations, skipping:\n".$fileName."\n\n";
				continue;
			}

			if (isset($this->library[$libraryId])) {
				echo "- Possible duplicate, skipping:\n".$fileName."\n\n";
				continue;
			}

			$this->library[$libraryId] = new LibraryItem($this, $libraryId, $fileName, $json);

			$filePath = $this->mb_dirname($fileName);
			$brokenFile = false;
			$needsConverting = false;

			for ($i = 0, $l = count($json); $i < $l; $i++) {
				$f = $json[$i];

				if (empty($f[static::PATH_KEY])) {
					echo ". No path, preparing to fix:\n".$fileName."|".$i."\n\n";
					if (!in_array($libraryId, $this->brokenLibraryFiles)) {
						$this->brokenLibraryFiles[] = $libraryId;
					}
				} else {
					$realSrcFile = $f[static::PATH_KEY];
					$absPath = mb_substr($realSrcFile, 0, 1) === '/';
					$realSrcFile = $this->mb_trim($realSrcFile, '/');
					$realSrcFile = mb_split('[|]', $realSrcFile)[0];

					if (!$absPath) {
						$realSrcFile = $filePath.DS.$realSrcFile;
					}

					$realSrcFile = str_replace('/', DS, $realSrcFile);

					if (!$realpath = $this->truepath($realSrcFile)) {
						if (!$brokenFile) {
							echo ". Broken path, preparing to fix:\n".$fileName.".\n";
							$this->brokenLibraryFiles[] = $libraryId;
							$brokenFile = true;
						}
						echo $this->mb_basename($realSrcFile)."\n";
					} else if ($this->convertPaths && (($absPath && $this->relativePaths) || (!$absPath && !$this->relativePaths))) {
						if (!$needsConverting) {
							echo "Paths to be converted in:\n".$fileName."\n";
							$this->brokenLibraryFiles[] = $libraryId;
							$needsConverting = true;
						}
						echo $this->mb_basename($realSrcFile)."\n";
					}
				}
			}

			if ($brokenFile) {
				echo "\n";
			}
		}
	}

	/**
	 * @param array $data
	 * @return null|string
	 */
	protected function getDurationId(array $data)
	{
		$keys = array_reduce($data, function ($result, $array) {
			if (isset($array[static::DURATION_KEY])) {
				$result[] = substr($array[static::DURATION_KEY], 0, -1);
			} else if (isset($array[static::PATH_KEY])) {
				echo "No duration found for ".$array[static::PATH_KEY]."\n";
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
	 * Recursively walks through directory tree and collects file names with needed extensions
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

		foreach ($contents as $path) {
			if (is_dir($this->win.$rootDir.DS.$path)) {
				$result = $this->scanDirectories($rootDir.DS.$path, $findTags, $result);
			} else {
				$ext = $this->mb_ext($path);

				if ($findTags) {
					if ($ext === static::EXT) {
						$result[] = $rootDir.DS.$path;
					}
				} else if (!$dirAdded && in_array($ext, $this->mediaExtensions)) {
					$result[] = [$rootDir, $contents];
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
		$path = str_replace('/', DS, $path);
		$path = mb_strrpos($path, DS) === false ? $path : mb_substr($path, mb_strrpos($path, DS) + 1);
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
		$path = str_replace('/', DS, $path);
		return mb_substr($path, 0, mb_strrpos($path, DS));
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

		if (mb_substr($path, 1, 1) !== ':') {
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
	public function mkdir($path, $chmod = 0755, $recursive = false)
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
}

/**
 * Class LibraryItem
 */
class LibraryItem
{
	public $sync, $id, $realPath, $tags, $exists;

	/**
	 * @param TagSync $sync
	 * @param string $libraryId
	 * @param string $file
	 * @param array $tags
	 */
	public function __construct(TagSync $sync, $libraryId, $file, array $tags)
	{
		$this->sync = $sync;
		$this->tags = $tags;
		$this->id = $libraryId;
		$this->realPath = $file;
	}

	/**
	 * @param array $tags
	 * @return bool
	 */
	public function setPaths(array $tags)
	{
		$changed = false;

		foreach ($this->tags as &$itemTag) {
			foreach ($tags as $fileTag) {
				if ($itemTag[TagSync::DURATION_KEY] === $fileTag[TagSync::DURATION_KEY]) {
					echo "Fixing path:\n";
					echo "- ".$itemTag[TagSync::PATH_KEY]."\n";
					echo "+ ".$fileTag[TagSync::PATH_KEY]."\n\n";
					$itemTag[TagSync::PATH_KEY] = $fileTag[TagSync::PATH_KEY];
					$changed = true;
				}
			}
		}

		return $changed;
	}

	/**
	 * Saves the library item
	 *
	 * @return bool|int
	 */
	public function save()
	{
		if (!$this->tags) {
			echo "Nothing to save in\n".$this->realPath."\n\n";
			return false;
		}

		$destFileDir = $this->sync->mb_dirname($this->realPath);

		if (!file_exists($this->sync->win.$destFileDir)) {
			if (!$this->sync->mkdir($destFileDir, $this->sync->dir_chmod, true)) {
				echo "Failed to create directory ".$destFileDir."\nSkipping: ".$this->realPath."\n\n";
				return false;
			}
			// Workaround for asynchronous file systems (like Samba)
			$t = 0;
			while (!file_exists($this->sync->win.$destFileDir)) {
				$t++;
				if ($t === 20) {
					return false;
				}
				sleep(0.5);
			}
		}

		echo "Saving: ".$this->realPath."\n";

		$k = TagSync::PATH_KEY;

		if ($this->sync->relativePaths && ($this->sync->convertPaths || !file_exists($this->sync->win.$this->realPath))) {
			foreach ($this->tags as &$tag) {
				if ($this->sync->isWindows) {
					$tag[$k] = $this->sync->mb_trim($tag[$k], '/');
				}
				$tag[$k] = $this->sync->findRelativePath($this->sync->mb_dirname($this->realPath), $tag[$k]);
				$tag[$k] = str_replace(DS, '/', $tag[$k]);
			}
		}

		if ($this->sync->isWindows) {
			foreach ($this->tags as &$tag) {
				if (mb_strpos($tag[$k], ':') !== false) {
					$tag[$k] = strtoupper(mb_substr($tag[$k], 0, 2)).mb_substr($tag[$k], 2);
				}
			}
		}

		$result = file_put_contents(
			$this->sync->win.$this->realPath,
			chr(0xEF).chr(0xBB).chr(0xBF).json_encode(
				$this->tags,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);

		if ($result) {
			$this->exists = true;
		}

		return $result;
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
