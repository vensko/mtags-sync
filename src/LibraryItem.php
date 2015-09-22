<?php

/**
 * Class LibraryItem
 */
class LibraryItem
{
	public $sync, $id, $realPath, $tags, $exists,
		$fixedPaths = false;

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
				if (empty($itemTag[TagSync::DURATION_KEY])) {
					continue;
				}

				if (empty($fileTag[TagSync::DURATION_KEY]) || $itemTag[TagSync::DURATION_KEY] === $fileTag[TagSync::DURATION_KEY]) {
					echo "Fixing path:\n";
					echo "- ".$itemTag[TagSync::PATH_KEY]."\n";
					echo "+ ".$fileTag[TagSync::PATH_KEY]."\n\n";
					$itemTag[TagSync::PATH_KEY] = $fileTag[TagSync::PATH_KEY];
					$changed = true;
				}
			}
		}

		if ($changed) {
			$this->fixedPaths = true;
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

		$destFileDir = $this->sync->dirname($this->realPath);

		if (!file_exists($this->sync->win.$destFileDir)) {
			if (!$this->sync->mkdir($destFileDir, $this->sync->dir_chmod, true)) {
				echo "Failed to create directory ".$destFileDir."\nSkipping: ".$this->realPath."\n\n";
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

		if ($this->sync->relativePaths && ($this->fixedPaths || $this->sync->convertPaths || !file_exists($this->sync->win.$this->realPath))) {
			foreach ($this->tags as $i => $tag) {
				if ($this->sync->isWindows) {
					$this->tags[$i][$k] = $this->sync->mb_trim($this->tags[$i][$k], '/');
				}
				$this->tags[$i][$k] = $this->sync->findRelativePath($this->sync->dirname($this->realPath), $this->tags[$i][$k]);
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

		$json = json_encode(
			$this->tags,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if (!$json) {
			echo "Saving failed (JSON error #".json_last_error()."):\n".$this->realPath."\n\n";
			return false;
		}

		echo "Saving: ".$this->realPath."\n";

		$result = file_put_contents(
			$this->sync->win.$this->realPath,
			chr(0xEF).chr(0xBB).chr(0xBF).$json
		);

		if ($result) {
			$this->exists = true;
		}

		return $result;
	}
}
