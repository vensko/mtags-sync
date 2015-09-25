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

		if ($this->file && mb_substr($this->file, -5) !== '.'.TagSync::EXT) {
			$this->file .= '.'.TagSync::EXT;
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
				echo "Fixing path:\n";

				if (!empty($this->tags[$k][TagSync::PATH_KEY])) {
					echo "- ".$this->tags[$k][TagSync::PATH_KEY]."\n";
				} else {
					echo "- [EMPTY]\n";
				}

				$this->tags[$k][TagSync::PATH_KEY] = ($this->sync->isWindows ? '/' : '').str_replace(DS, '/', $dir).'/'.$file;

				echo "+ ".$this->tags[$k][TagSync::PATH_KEY]."\n";

				$changed = true;
			} else if ($this->sync->verbose) {
				echo "No new path for ".$this->tags[$k][TagSync::PATH_KEY]."\n\n";
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
			echo "No file path set.\n\n";
			return false;
		}

		if (!$this->tags) {
			echo "Nothing to save in\n".$file."\n\n";
			return false;
		}

		$destFileDir = $this->sync->dirname($file);

		if (!file_exists($this->sync->win.$destFileDir)) {
			if (!$this->sync->mkdir($destFileDir, $this->sync->dir_chmod, true)) {
				echo "Failed to create directory ".$destFileDir."\nSkipping: ".$file."\n\n";
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
					$this->tags[$i][$k] = $this->sync->mb_trim($this->tags[$i][$k], '/');
				}
				$this->tags[$i][$k] = $this->sync->findRelativePath($this->sync->dirname($file), $this->tags[$i][$k]);
				$this->tags[$i][$k] = str_replace(DS, '/', $this->tags[$i][$k]);
			}
		}

		if ($this->sync->isWindows) {
			foreach ($this->tags as $i => $tag) {
				if (mb_strpos($this->tags[$i][$k], ':') !== false) {
					$this->tags[$i][$k] = strtoupper(mb_substr($this->tags[$i][$k], 0, 2)).mb_substr($this->tags[$i][$k], 2);
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
			echo "Saving failed (JSON error #".json_last_error()."):\n".$file."\n\n";
			return false;
		}

		if ($this->sync->emulation) {
			echo "Saving (EMULATION): ".$file."\n";
			$result = true;
		} else {
			if ($this->sync->verbose) {
				echo "Saving: ".$file."\n";
			}

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
