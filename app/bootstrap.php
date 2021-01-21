<?php

define('DS', DIRECTORY_SEPARATOR);
define('PS', PATH_SEPARATOR);
define('BP', dirname(dirname(__FILE__)));

ini_set('log_errors ', 1);
ini_set('error_log', BP . DS . 'logs' . DS . 'error.log');

// Only approved libraries may be used in plugins
set_include_path(BP . DS . 'lib');

include_once 'Middleware' . DS . 'Autoload.php';
require BP.DS.'vendor/autoload.php';

final class Middleware
{
    /** @var null|SimpleXMLElement */
    private $_config;

    /** @var null|SimpleXMLElement */
    private $_info;

    /** @var null|string */
    private $_plugin;
    private $_pluginInstance;

    /** @var resource[] */
    private $_fileHandles = [];

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
        $this->_pluginInstance->isDebug($debug);
        if (PHP_SAPI === 'cli' && $debug) {
            echo "Debug mode is ENABLED\n";
        }
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
        if ( ! $this->getConfig("plugin/{$this->_plugin}/events/{$resource}/{$event}") && $topic != 'test:ping') {
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
     * Retrieve configuration value
     *
     * @param string $path
     * @return null|string
     * @throws Exception
     */
    public function getConfig($path)
    {
        if (empty($path)) {
            return NULL;
        }

        if ( ! $this->_config) {
            // Load plugin configuration as SimpleXMLElement object
            $file = $this->getPluginPath($this->_plugin) . DS . 'etc' . DS . 'config.xml';
            if ( ! file_exists($file)) {
                throw new Exception(sprintf('The configuration file "%s" does not exist.', $file));
            }
            if ( ! is_readable($file)) {
                throw new Exception(sprintf('The configuration file "%s" is not readable.', $file));
            }
            $pluginConfig = simplexml_load_file($file, 'Varien_Simplexml_Element'); /* @var $pluginConfig Varien_Simplexml_Element */
            if ( ! $pluginConfig) {
                throw new Exception('Error loading config.xml file.');
            }

            // Load local config and merge over plugin config
            $localConfig = simplexml_load_file(BP.DS.'app'.DS.'etc'.DS.'local.xml', 'Varien_Simplexml_Element'); /* @var $localConfig Varien_Simplexml_Element */
            if ( ! $localConfig) {
                throw new Exception('Could not load app/etc/local.xml');
            }
            $pluginConfig->extend($localConfig, TRUE);
            $this->_config = $pluginConfig;
        }

        $result = $this->_config->descend('default/'.$path);
        if ($result === FALSE) {
            return NULL;
        }
        return $result->__toString();
    }

    /**
     * Retrieve static information value
     *
     * @param string $path
     * @return null|string|Varien_Simplexml_Element[]
     * @throws Exception
     */
    public function getPluginInfo($path)
    {
        if (empty($path)) {
            return NULL;
        }

        // Load plugin static information as SimpleXMLElement object
        if ( ! $this->_info) {
            $file = $this->getPluginPath($this->_plugin) . DS . 'etc' . DS . 'plugin.xml';
            if ( ! file_exists($file)) {
                throw new Exception(sprintf('The plugin information file "%s" does not exist.', $file));
            }
            if ( ! is_readable($file)) {
                throw new Exception(sprintf('The plugin information file "%s" is not readable.', $file));
            }
            $pluginInfo = simplexml_load_file($file, 'Varien_Simplexml_Element'); /** @var $pluginInfo Varien_Simplexml_Element */
            if ( ! $pluginInfo) {
                throw new Exception('Error loading plugin.xml file.');
            }
            $this->_info = $pluginInfo;
        }

        $result = $this->_info->descend($path);
        if ($result === FALSE) {
            return NULL;
        }
        return $result->hasChildren() ? (array) $result : $result->__toString();
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
     * @param string $name
     * @return false|resource
     */
    public function getLogFileHandle($name)
    {
        if ( ! isset($this->_fileHandles[$name])) {
            $this->_fileHandles[$name] = fopen(BP . DS . 'logs' . DS . $name, 'a+');
        }
        return $this->_fileHandles[$name];
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
        if ($lifetime === FALSE) {
            $lifetime = 7200;
        }
        $expires = $lifetime ? time() + $lifetime : NULL;
        if ( ! is_writable(dirname($path))) {
            throw new Exception('Cannot write to tmp directory.');
        }
        return !! file_put_contents($path, serialize(array('data' => $data, 'expires' => $expires)));
    }

    /**
     * @param $key
     */
    public function removeCache($key) {
        $path = BP . DS . 'tmp' . DS . 'cache-'.$key;
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Render page HTML
     *
     * @param string $template
     * @return string
     * @throws Exception
     */
    public function renderPage($template)
    {
        $dir = BP . DS . 'views';
        $fileName = pathinfo($template, PATHINFO_FILENAME);
        $templatePath = $dir . DS . $template;
        if ( ! file_exists($templatePath)) {
            throw new Exception(sprintf('The template file "%s" does not exist.', $template));
        }
        if ( ! is_readable($templatePath)) {
            throw new Exception(sprintf('The template file "%s" is not readable.', $template));
        }

        $rendererPath = $dir . DS . $fileName . '.php';

        $plugin = $this->_pluginInstance; /** @var $plugin Plugin_Abstract */

        ob_start();
        if (file_exists($rendererPath) && is_readable($rendererPath)) {
            include $rendererPath;
        }
        include $templatePath;
        $html = ob_get_clean();
        return $html;
    }

    /**
     * @param string $method
     * @param array $query
     * @param array $headers
     * @param string $data
     * @throws Exception
     */
    public function callbackController($method, $query, $headers, $data)
    {
        ini_set('zlib.output_compression', 'Off');
        header('Content-Encoding: none', TRUE);
        header('Connection: close', TRUE);
        ob_start();

        if (empty($query['secret_key'])) {
            throw new Exception('Unknown secret key.', 409);
        }
        if ($query['secret_key'] != $this->getConfig('middleware/api/secret_key')) {
            throw new Exception('Invalid secret key.');
        }
        $callMethod = $this->_pluginInstance->getPluginInfo('routes/'.$method);
        if ( ! $callMethod) {
            throw new Exception('Unknown plugin method.', 409);
        }
        if ( ! is_callable(array($this->_pluginInstance, $callMethod))) {
            throw new Exception('Plugin method is not callable.', 409);
        }
        if (FALSE === ($response = $this->_pluginInstance->$callMethod($query, $headers, $data))) {
            throw new Exception('Callback request failed.', 409);
        }

        echo $response;
    }

    /**
     * Get url for configuring a webhook in ShipStream to be able to respond to events
     *
     * @return string
     */
    public function getRespondUrl()
    {
        $url = trim($this->getConfig('middleware/system/base_url'));
        if ( ! $url) {
            throw new Exception('The base url is not configured (middleware/system/base_url).');
        }
        $params = [
            'plugin' => $this->_plugin,
        ];
        $url .= (substr($url, -1) != '/' ? '/' : '').'respond.php?'.http_build_query($params, '', '&');
        return $url;
    }

    /**
     * Get url for registering with third-party webhooks
     *
     * @return string
     */
    public function getWebhookUrl()
    {
        return $this->_pluginInstance->getWebhookUrl();
    }

    /**
     * Get callback url
     *
     * @return string
     */
    public function getCallbackUrl($method)
    {
        return $this->_pluginInstance->getCallbackUrl($method);
    }

    /**
     * Retrieve OAuth url
     *
     * @param array $params
     * @return string
     */
    public function oauthGetUrl($params = array())
    {
        return $this->_pluginInstance->oauthGetUrl($params);
    }

    /**
     * @param array $request
     * @return void
     */
    public function oauthHandleRedirect(array $request)
    {
        $this->_pluginInstance->oauthHandleRedirect($request);
    }

    /**
     * @param array $params
     * @return void
     */
    public function oauthDisconnect(array $params)
    {
        $this->_pluginInstance->oauthDisconnect($params);
    }
}
