<?php

namespace TagSync;

/**
 * Class TagSync_getID3
 */
class getID3 extends \getID3
{
	/**
	 * @var TagSync
	 */
	public static $mtags = null;

	public function openfile($filename, $filesize = null)
	{
		try {
			if (!empty($this->startup_error)) {
				throw new \Exception($this->startup_error);
			}
			if (!empty($this->startup_warning)) {
				$this->warning($this->startup_warning);
			}

			// init result array and set parameters
			$this->filename = $filename;
			$this->info = [];
			$this->info['php_memory_limit'] = (($this->memory_limit > 0) ? $this->memory_limit : false);

			if (is_file(static::$mtags->win.$filename) && ($this->fp = fopen(static::$mtags->win.$filename, 'rb'))) {
				// great
			} else {
				$errormessagelist = [];
				if (!is_file($filename)) {
					$errormessagelist[] = '!is_file';
				}
				if (empty($errormessagelist)) {
					$errormessagelist[] = 'fopen failed';
				}
				throw new \Exception('Could not open "'.$filename.'" ('.implode('; ', $errormessagelist).')');
			}

			$this->info['filesize'] = $filesize !== null ? $filesize : filesize(static::$mtags->win.$filename);

			$this->info['avdataoffset'] = 0;
			$this->info['avdataend'] = $this->info['filesize'];
			$this->info['fileformat'] = '';                // filled in later
			$this->info['audio']['dataformat'] = '';                // filled in later, unset if not used
			$this->info['video']['dataformat'] = '';                // filled in later, unset if not used
			$this->info['tags'] = [];           // filled in later, unset if not used
			$this->info['error'] = [];           // filled in later, unset if not used
			$this->info['warning'] = [];           // filled in later, unset if not used
			$this->info['comments'] = [];           // filled in later, unset if not used
			$this->info['encoding'] = $this->encoding;   // required by id3v2 and iso modules - can be unset at the end if desired

			return true;

		} catch (\Exception $e) {
			$this->error($e->getMessage());
		}

		return false;
	}
}
