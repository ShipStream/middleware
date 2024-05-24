<?php

class Middleware_Autoload
{
    private string $_pluginPrefix;
    private array $_pluginLoader;

    /**
     * Register SPL autoload function
     */
    static public function register(string $pluginPrefix, array $pluginLoader)
    {
        $autoloader = new Middleware_Autoload($pluginPrefix, $pluginLoader);
        spl_autoload_register(array($autoloader, 'autoload'));
    }

    public function __construct(string $pluginPrefix, array $pluginLoader)
    {
        $this->_pluginPrefix = $pluginPrefix;
        $this->_pluginLoader = $pluginLoader;
    }

    /**
     * Load class source code
     */
    public function autoload(string $class): bool
    {
        if (strpos($class, $this->_pluginPrefix) === 0) {
            return call_user_func($this->_pluginLoader, str_replace($this->_pluginPrefix, '', $class));
        }
        $classFile = str_replace(' ', DIRECTORY_SEPARATOR, ucwords(str_replace('_', ' ', $class)));
        $classFile.= '.php';
        return include $classFile;
    }
}
