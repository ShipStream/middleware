<?php

define('DS', DIRECTORY_SEPARATOR);
define('PS', PATH_SEPARATOR);
define('BP', dirname(dirname(__FILE__)));

ini_set('log_errors ', 1);
ini_set('error_log', BP . DS . 'logs' . DS . 'error.log');

// Only approved libraries may be used in plugins
set_include_path(BP . DS . 'lib');

include_once 'Middleware' . DS . 'Autoload.php';

final class Middleware
{
    /** @var null|SimpleXMLElement */
    private $_config;

    /** @var null|string */
    private $_plugin;
    private $_pluginInstance;

    private static $_instance;

    public function __construct($plugin, $debug = FALSE)
    {
        // Ensure that user cannot instantiate Middleware
        if (self::$_instance) {
            throw new Exception('Middleware instance may not be instantiated by user.');
        }
        self::$_instance = $this;

        $this->_plugin = $plugin;

        // Load plugin instance
        Middleware_Autoload::register($plugin, array($this, 'loadPluginClass'));
        $class = $plugin.'_Plugin';
        $object = new $class($plugin);
        if ( ! $object instanceof Plugin_Abstract) {
            throw new Exception('The plugin object must be an instance of Plugin_Abstract.');
        }
        $object->_setMiddleware($this);
        $this->_pluginInstance = $object;
        $this->_pluginInstance->_isDebug($debug);
    }

    /**
     * Call the method of the plugin
     *
     * @param string $method
     * @return void
     * @throws Exception
     */
    public function run($method)
    {
        if ( ! is_callable($this->_plugin, $method)) {
            throw new Exception(sprintf('The plugin method "%s" is not callable.', $method));
        }
        $this->_pluginInstance->$method();
    }

    /**
     * Subscribe for the Pub/Sub server events
     *
     * @return void
     * @throws Exception
     */
    public function subscribe()
    {
        $isActive = (bool) $this->getConfig('middleware/pubsub/active');
        if ( ! $isActive) {
            throw new Exception('The pub/sub feature is not active.');
        }

        $server = array_map('trim', explode(':', strval($this->getConfig('middleware/pubsub/server'))));
        $host = isset($server[0]) ? $server[0] : NULL;
        $port = isset($server[1]) ? $server[1] : NULL;
        if (empty($host)) {
            throw new Exception('The pub/sub host is not configured.');
        }

        $command = trim(strval($this->getConfig('middleware/pubsub/command')));
        if (empty($command)) {
            throw new Exception('The pub/sub command is not configured.');
        }
        $timeout = intval($this->getConfig('middleware/pubsub/timeout'));
        $credis = new Credis_Client($host, $port, $timeout);
        $credis->pSubscribe($command.':*', function ($credis, $pattern, $channel, $message) {
            list($key, $topic) = array_map('trim', explode(':', $channel, 2));
            $messageData = json_decode($message, TRUE);
            try {
                $this->respond($topic, $messageData);
            } catch (Exception $e) {
                $this->logException($e);
            }
        });
    }

    /**
     * Have the plugin respond to an event
     *
     * @param string $topic
     * @param array $message
     * @return void
     * @throws Exception
     */
    public function respond($topic, $message)
    {
        list($resource, $event) = explode(':', $topic, 2);
        if ( ! $this->getConfig("plugin/{$this->_plugin}/events/{$resource}/{$event}")) {
            throw new Exception(sprintf('The plugin is not configured to respond to the topic "%s".', $topic));
        }
        $methodName = 'respond'.ucfirst($resource).ucfirst($event);
        if ( ! is_callable($this->_plugin, $methodName)) {
            throw new Exception(sprintf('The plugin method "%s" is not callable.', $methodName));
        }
        $this->_pluginInstance->$methodName($message);
    }

    /**
     * @param array $query
     * @param array $headers
     * @param string $data
     * @throws Exception
     */
    public function webhookController($query, $headers, $data)
    {
        ini_set('zlib.output_compression', 'Off');
        header('Content-Encoding: none', TRUE);
        header('Connection: close', TRUE);
        ob_start();

        if ( ! $this->_pluginInstance->verifyWebhook($query, $headers, $data)) {
            throw new Exception('Webhook request not authenticated.', 403);
        }
        ignore_user_abort(true);

        // Process webhook after response is confirmed
        if ( ! $this->_pluginInstance->handleWebhook($query, $headers, $data)) {
            throw new Exception('Webhook request failed.', 409);
        }
        $this->yieldWebhook();
    }

    /**
     * Respond to webhook. Can be called by plugin to allow plugin to respond early and keep working
     */
    public function yieldWebhook()
    {
        static $hasYielded = FALSE;
        if ( ! $hasYielded) {
            $hasYielded = TRUE;
            $size = ob_get_length();
            header("Content-Length: $size", TRUE);
            http_response_code(200);
            ob_end_flush();
            ob_flush();
            flush();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        }
    }

    /**
     * @param $plugin
     * @return string
     */
    public function getPluginPath($plugin)
    {
        return  BP . DS . 'app' . DS . 'code' . DS . 'community'. DS. str_replace('_', DS, $plugin);
    }

    /**
     * Used by Middleware_Autoload to load plugin classes from plugin directory. Does not allow multiple
     * plugins to be auto-loaded in the same process.
     *
     * @param string $suffix
     * @return string
     * @throws Exception
     */
    public function loadPluginClass($suffix)
    {
        $file = $this->getPluginPath($this->_plugin) . DS . str_replace(array('_','\\'), DS, $suffix). '.php';
        if ( ! file_exists($file)) {
            throw new Exception(sprintf('The plugin file "%s" does not exist.', $file), 404);
        }
        require $file;
        $class = $this->_plugin.$suffix;
        if ( ! class_exists($class, FALSE)) {
            throw new Exception('The plugin class does not exist.', 404);
        }
        return TRUE;
    }

    /**
     * Retrieve configuration file as Simple XML Element object
     *
     * @param string $path
     * @return SimpleXMLElement
     * @throws Exception
     */
    public function getConfig($path)
    {
        if ( ! $this->_config)
        {
            // Load plugin config
            $file = $this->getPluginPath($this->_plugin) . DS . 'etc' . DS . 'config.xml';
            if ( ! file_exists($file)) {
                throw new Exception(sprintf('The configuration file "%s" does not exist.', $file));
            }
            if ( ! is_readable($file)) {
                throw new Exception(sprintf('The configuration file "%s" is not readable.', $file));
            }
            $pluginConfig = simplexml_load_file($file, 'Varien_Simplexml_Element'); /* @var $pluginConfig Varien_Simplexml_Element */
            if ( ! $pluginConfig) {
                throw new Exception('Error loading plugin config.xml.');
            }

            // Load local config and merge over plugin config
            $localConfig = simplexml_load_file(BP.DS.'app'.DS.'etc'.DS.'local.xml', 'Varien_Simplexml_Element'); /* @var $localConfig Varien_Simplexml_Element */
            if ( ! $localConfig) {
                throw new Exception('Could not load app/etc/local.xml');
            }
            $pluginConfig->extend($localConfig, TRUE);
            $this->_config = $pluginConfig;
        }

        if (empty($path)) {
            return NULL;
        }
        $result = $this->_config->descend('default/'.$path);
        if ($result === FALSE) {
            return NULL;
        }
        return $result->__toString();
    }

    /**
     * Log messages
     *
     * @param string $message
     * @param null|string $destination
     * @return void
     */
    public function log($message, $destination = NULL)
    {
        if ($destination === NULL) {
            $destination = $this->getConfig('middleware/system/log');
            if ( ! $destination) {
                $destination = 'stdout';
            }
        }
        if ($destination == 'stdout') {
            echo $message."\n";
        } else if ($destination && $destination != 'syslog') {
            error_log(date('c').' '.$message."\n", 3, BP . DS . 'logs' . DS . $destination);
        } else {
            error_log($message);
        }
    }

    /**
     * Write exception to log
     *
     * @param Exception $e
     * @return void
     */
    public function logException(Exception $e)
    {
        $this->log("\n" . $e->__toString());
    }

    /**
     * Load data from cache
     *
     * @param string $key
     * @return null|string|array
     */
    public function loadCache($key)
    {
        $path = BP . DS . 'tmp' . DS . 'cache-'.$key;
        if ( ! file_exists($path)) {
            return NULL;
        }
        $data = unserialize(file_get_contents($path));
        if ( ! $data['expires'] || time() < $data['expires']) {
            return $data['data'];
        } else {
            unlink($path);
            return NULL;
        }
    }

    /**
     * @param string $key
     * @param string|array $data
     * @param int|null $lifetime
     * @throws Exception
     * @return bool
     */
    public function saveCache($key, $data, $lifetime)
    {
        $path = BP . DS . 'tmp' . DS . 'cache-'.$key;
        $expires = $lifetime ? time() + $lifetime : NULL;
        if ( ! is_writable(dirname($path))) {
            throw new Exception('Cannot write to tmp directory.');
        }
        return !! file_put_contents($path, serialize(array('data' => $data, 'expires' => $expires)));
    }

}
