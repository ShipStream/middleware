<?php

/**
 * Helper class for filesystem locking.
 */
class Plugin_Lock
{
    /**
     * Filesystem lock properties
     */
    protected $_isLocked = NULL;
    protected $_lockFile = NULL;
    protected $_fileName = NULL;

    private $_lockFilePathPrefix;

    /**
     * Set file name
     *
     * @param string $key
     */
    public function __construct($key)
    {
        $this->_fileName = preg_replace('/[^\w-]+/', '-', $key);
        $this->_isLocked = NULL;
        $this->_lockFile = NULL;
    }

    /**
     * Lock process without blocking.
     * This method allow protect multiple process running and fast lock validation.
     *
     * @return $this
     */
    public function lock()
    {
        $this->_isLocked = !! flock($this->_getLockFile(), LOCK_EX | LOCK_NB);
        return $this;
    }

    /**
     * Lock and block process.
     * If new instance of the process will try validate locking state
     * script will wait until process will be unlocked
     *
     * @return $this
     */
    public function lockAndBlock()
    {
        $this->_isLocked = !! flock($this->_getLockFile(), LOCK_EX);
        return $this;
    }

    /**
     * Unlock process
     *
     * @return $this
     */
    public function unlock()
    {
        $this->_isLocked = ! flock($this->_getLockFile(), LOCK_UN);
        return $this;
    }

    /**
     * Check if process is locked
     *
     * @return bool
     */
    public function isLocked()
    {
        if ($this->_isLocked !== NULL) {
            return $this->_isLocked;
        } else {
            $fp = $this->_getLockFile();
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                return ! flock($fp, LOCK_UN);
            }
            return TRUE;
        }
    }

    /**
     * Close file resource if it was opened
     */
    public function __destruct()
    {
        if ($this->_lockFile) {
            fclose($this->_lockFile);
        }
    }

    /**
     * Get lock file resource
     *
     * @return resource
     */
    protected function _getLockFile()
    {
        if ($this->_lockFile === NULL) {
            $file = $this->_lockFilePathPrefix . $this->_fileName;
            if (is_file($file)) {
                $this->_lockFile = fopen($file, 'w');
            } else {
                $this->_lockFile = fopen($file, 'x');
            }
            fwrite($this->_lockFile, date('r'));
        }
        return $this->_lockFile;
    }

    /**
     * @param string $path
     */
    public function _setLockFilePathPrefix($path)
    {
        $this->_lockFilePathPrefix = $path;
    }

}
