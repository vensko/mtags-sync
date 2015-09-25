<?php

namespace TagSync;

/**
 * Class LibraryItem
 */
class LibraryItem
{
	public $id, $sync, $file, $tags, $exists, $broken, $fixedPaths, $modified;

	protected $indexIds = [
		TagSync::SIZE_KEY => null,
		TagSync::DURATION_KEY => null,
		TagSync::INDEX_KEY => null,
		TagSync::MD5_KEY => null,
		TagSync::FP_KEY => null
	];

	/**
	 * @param TagSync $sync
	 * @param string $libraryId
	 * @param string $file
	 * @param array $tags
	 */
	public function __construct(TagSync $sync, array $tags, $file = null)
	{
		$this->sync = $sync;
		$this->tags = $tags;
		$this->file = $file;

		if ($this->file) {
			$ext = explode('.', $this->file);
			if (array_pop($ext) !== TagSync::EXT) {
				$this->file .= '.'.TagSync::EXT;
			}
		}
	}

	public function getIdKeys()
	{
		return array_keys($this->indexIds);
	}

	public function getDurationId()
	{
		return $this->getIndexId(TagSync::DURATION_KEY);
	}

	public function getFileId()
	{
		return $this->getIndexId(TagSync::PATH_KEY);
	}

	public function getFingerprintId()
	{
		return $this->getIndexId(TagSync::FP_KEY);
	}

	public function setIndexId($key, $value)
	{
		$this->indexIds[$key] = $value;

		return $this;
	}

	public function getIndexId($key)
	{
		if (!empty($this->indexIds[$key])) {
			return $this->indexIds[$key];
		}

		$keys = [];

		foreach ($this->tags as $i) {
			if (empty($i[$key])) {
				return null;
			}

			$keys[] = $i[$key];
		}

		sort($keys);

		return $this->indexIds[$key] = md5(implode('|', $keys));
	}

	/**
	 * @param $key
	 * @param $dir
	 * @param array $index
	 * @return bool
	 */
	public function setPaths($key, $dir, array $index)
	{
		$changed = false;

		foreach ($this->tags as $k => $tag) {
			$file = array_search($tag[$key], $index);

			if ($file) {
				$this->sync->log("Fixing path:", TagSync::LOG_WARNING, null, "\n");

				if (!empty($this->tags[$k][TagSync::PATH_KEY])) {
					$this->sync->log("- ".$this->tags[$k][TagSync::PATH_KEY], TagSync::LOG_WARNING, null, "\n", TagSync::CONSOLE_WHITE);
				} else {
					$this->sync->log("- [EMPTY]", TagSync::LOG_WARNING, null, "\n", TagSync::CONSOLE_WHITE);
				}

				$this->tags[$k][TagSync::PATH_KEY] = ($this->sync->isWindows ? '/' : '').str_replace(DS, '/', $dir).'/'.$file;

				$this->sync->log("+ ".$this->tags[$k][TagSync::PATH_KEY], TagSync::LOG_WARNING, null, "\n\n", TagSync::CONSOLE_WHITE);

				$changed = true;
			} else {
				$this->sync->log("No new path for", TagSync::LOG_DEBUG, $this->tags[$k][TagSync::PATH_KEY]);
			}
		}

		if ($changed) {
			$this->fixedPaths = true;
			$this->modified = true;
		}

		return $changed;
	}

	/**
	 * Saves a library item
	 *
	 * @param string|null $file
	 * @return bool|int
	 */
	public function save($file = null)
	{
		$file = $file ?: $this->file;

		if (!$file) {
			$this->sync->log("Attempted to save a library item without file path set.", TagSync::LOG_ERROR);
			return false;
		}

		if (!$this->tags) {
			$this->sync->log("Nothing to save in", TagSync::LOG_ERROR, $file);
			return false;
		}

		$destFileDir = $this->sync->dirname($file);

		if (!file_exists($this->sync->win.$destFileDir)) {
			if (!$this->sync->mkdir($destFileDir, $this->sync->dir_chmod, true)) {
				$this->sync->log("Failed to create directory", TagSync::LOG_ERROR, $destFileDir, "\n");
				$this->sync->log("Skipping", TagSync::LOG_ERROR, $file);
				return false;
			}
			// Workaround for asynchronous file systems like Samba
			$t = 0;
			while (!file_exists($this->sync->win.$destFileDir)) {
				$t++;
				if ($t === 20) {
					return false;
				}
				sleep(0.5);
			}
		}

		$k = TagSync::PATH_KEY;

		if ($this->sync->relativePaths && ($this->fixedPaths || $this->sync->convertPaths || !file_exists($this->sync->win.$file))) {
			foreach ($this->tags as $i => $tag) {
				if ($this->sync->isWindows) {
					$this->tags[$i][$k] = ltrim($this->tags[$i][$k], '/');
				}
				$this->tags[$i][$k] = $this->sync->findRelativePath($this->sync->dirname($file), $this->tags[$i][$k]);
				$this->tags[$i][$k] = str_replace(DS, '/', $this->tags[$i][$k]);
			}
		}

		if ($this->sync->isWindows) {
			foreach ($this->tags as $i => $tag) {
				if ($this->tags[$i][$k][2] === ':') {
					$disk = $this->tags[$i][$k][1];
					$this->tags[$i][$k] = ltrim($this->tags[$i][$k], '/');
					$this->tags[$i][$k] = ltrim($this->tags[$i][$k], $disk);
					$this->tags[$i][$k] = '/'.strtoupper($disk).$this->tags[$i][$k];
				}
			}
		}

		// Natural sort by source path
		$paths = array_column($this->tags, TagSync::PATH_KEY);
		natsort($paths);
		$tags = array_values(array_replace($paths, $this->tags));

		$json = json_encode(
			$tags,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if (!$json) {
			$this->sync->log("Saving failed (JSON error #".json_last_error().")", TagSync::LOG_ERROR, $file);
			return false;
		}

		if ($this->sync->emulation) {
			$this->sync->log("Saving (EMULATION):", TagSync::LOG_INFO, $file, "\n");
			$result = true;
		} else {
			$this->sync->log("Saving:", TagSync::LOG_DEBUG, $file, "\n");

			$result = file_put_contents(
				$this->sync->win.$file,
				chr(0xEF).chr(0xBB).chr(0xBF).$json
			);
		}

		if ($result) {
			$this->exists = true;
		}

		return $result;
	}
}
