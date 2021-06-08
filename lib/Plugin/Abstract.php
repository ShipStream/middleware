<?php

use Monolog\Formatter\LineFormatter;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Middleware;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Loguzz\Middleware\LogMiddleware;

/**
 * Abstract class for a plugin
 */
abstract class Plugin_Abstract implements Plugin_Interface
{
    const EMERG   = 0;  // Emergency: system is unusable
    const ALERT   = 1;  // Alert: action must be taken immediately
    const CRIT    = 2;  // Critical: critical conditions
    const ERR     = 3;  // Error: error conditions
    const WARN    = 4;  // Warning: warning conditions
    const NOTICE  = 5;  // Notice: normal but significant condition
    const INFO    = 6;  // Informational: informational messages
    const DEBUG   = 7;  // Debug: debug messages

    /** @var string */
    private $code;
    
    /** @var \Middleware */
    private $middleware;

    /** @var null|\Middleware_JsonClient */
    private $_client;

    /** @var bool */
    private $_isDebug = FALSE;

    /**
     * @param string $code
     */
    final public function __construct($code)
    {
        $this->code = $code;
    }

    /*
     * Abstract methods which may be overridden by plugins
     */

    /**
     * @param array $query
     * @param array $headers
     * @param string $data
     * @return bool
     */
    public function verifyWebhook($query, $headers, $data)
    {
        return FALSE;
    }

    /**
     * @param $query
     * @param $headers
     * @param $data
     * @return bool
     */
    public function handleWebhook($query, $headers, $data)
    {
        return FALSE;
    }

    /**
     * @param array $request
     * @return void
     */
    public function oauthHandleRedirect($request) {}

    /**
     * @param array $params
     * @return string
     */
    public function oauthGetRedirectUrl($params = array())
    {
        $params = array_merge(
            $params,
            ['plugin' => $this->code],
            ['action' => 'redirect']
        );
        $query = [];
        foreach ($params as $key => $value) {
            $query[] = $key.'/'.$value;
        }
        return $this->_getBaseUrl().'oauth.php/'.implode('/', $query).'/';
    }

    /**
     * Get the url which starts the OAuth connection
     *
     * @param null|string $redirectUrl
     * @return string
     */
    public function oauthGetConnectUrl($redirectUrl = NULL) {}

    /**
     * Get the button to setup the OAuth connection
     *
     * @param array $connectParams
     * @return string
     */
    public function oauthGetConnectButton($connectParams = array()) {}

    /**
     * Get the button to disconnect from OAuth
     *
     * @param array $params
     * @return void
     */
    public function oauthDisconnect($params = array()) {}

    /**
     * @param string $accessToken
     * @return mixed
     * @throws Exception
     */
    public function oauthSetTokenData($accessToken)
    {
        return $this->setState('oauth_access_token', $accessToken);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function oauthGetTokenData()
    {
        return $this->getState('oauth_access_token');
    }

    /**
     * @return void
     * @throws Exception
     */
    public function oauthValidateConfig() {}

    /**
     * @return string[]
     * @throws Plugin_Exception
     */
    public function oauthTest() {}

    /*
     * Available helper methods which CANNOT be overridden by plugins
     */

    /**
     * Respond to webhook early to avoid timeouts
     */
    final public function yieldWebhook()
    {
        $this->middleware->yieldWebhook();
    }

    /**
     * Wrapper for "call" method
     *
     * @param string $method
     * @param array $args
     * @return mixed
     * @throws Exception
     */
    final public function call($method, $args = array())
    {
        return $this->_getClient()->call($method, $args, TRUE);
    }

    /**
     * @param array|string $data
     * @param int|string|array|stdClass|null $value
     * @return mixed
     * @throws Exception
     */
    final public function setState($data, $value = NULL)
    {
        if (is_string($data)) {
            $data = $this->code.'_'.$data;
        } elseif (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$this->code.'_'.$k] = $v;
                unset($data[$k]);
            }
        }
        return $this->call('state.set', array('data' => $data, 'value' => $value));
    }

    /**
     * @param array|string $keys
     * @return array|string|null
     * @throws Exception
     */
    final public function getState($keys)
    {
        if (is_string($keys)) {
            $keys = $this->code.'_'.$keys;
        } elseif (is_array($keys)) {
            $keys = array_map(function($key){ return $this->code.'_'.$key; }, $keys);
        }
        $data = $this->call('state.get', array($keys));
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $_k = preg_replace("/^{$this->code}_/", '', $k);
                $data[$_k] = $data[$k];
                unset($data[$k]);
            }
        }
        return $this->call('state.get', array($keys));
    }

    /**
     * Retrieve config value
     *
     * @param string $path
     * @return null|string
     * @throws Exception
     */
    final public function getConfig($path)
    {
        return $this->middleware->getConfig("plugin/{$this->code}/$path");
    }

    /**
     * Retrieve plugin information
     *
     * @param string $path
     * @return null|string|Varien_Simplexml_Element[]
     * @throws Exception
     */
    final public function getPluginInfo($path)
    {
        return $this->middleware->getPluginInfo("{$this->code}/$path");
    }

    /**
     * Get the merchant's configured default timezone
     *
     * @return \DateTimeZone
     */
    final public function getTimeZone()
    {
        return new DateTimeZone($this->middleware->getConfig('middleware/system/timezone') ?: 'America/New_York');
    }

    /**
     * Get the name of the given warehouse by id (uses cache with 1 hour TTL)
     *
     * @param int $id
     * @return string|null
     */
    final public function getWarehouseName($id)
    {
        if ( ! ($warehouses = $this->loadCache('$warehouseNames'))) {
            $warehouses = $this->call('warehouse.list');
            $warehouseNames = [];
            foreach ($warehouses as $warehouse) {
                $warehouseNames[$warehouse['warehouse_id']] = $warehouse['name'];
            }
            $this->saveCache('$warehouseNames', $warehouseNames, 3600);
        }

        return $warehouseNames[$id] ?? NULL;
    }

    /**
     * Get an instance of GuzzleClient - reuse this instance as much as possible and request a new instance for
     * each unique base_uri.
     *
     * @param array $options
     * @return Client
     */
    final public function getHttpClient(array $options)
    {
        if (empty($options['base_uri'])) {
            throw new Exception('The "base_uri" option is required.');
        }
        if (empty($options['handler'])) {
            $handlerStack = new HandlerStack();
            $handlerStack->setHandler(new CurlHandler());
            $options['handler'] = $handlerStack;
        }
        $options['handler']->push(Middleware::httpErrors());

        $options['handler']->push(Middleware::mapRequest(function (RequestInterface $request) {
            return $request->withHeader('User-Agent', 'ShipStream-Middleware/1.0 (Plugin;'.$this->code.')');
        }));

        if ($this->_isDebug) {
            $debugFileHandle = $this->middleware->getLogFileHandle('http_client.log');
            $logger = new Logger('http-requests');
            $logger->pushProcessor(function ($record) {
                $record['extra']['request_id'] = Mage::registry('logger_request_id');
                return $record;
            });
            $formatter = new LineFormatter("[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n", NULL, TRUE, TRUE);
            $streamHandler = new StreamHandler($debugFileHandle);
            $streamHandler->setFormatter($formatter);
            $logger->pushHandler($streamHandler);
            $loggerOptions = [
                'response_formatter' => new Plugin_ResponseFormatter(),
            ];
            $options['handler']->push(new LogMiddleware($logger, $loggerOptions), 'logger');
        }

        $defaultOptions = [
            'allow_redirects' => FALSE,
            'connect_timeout' => 1.5,
            'timeout' => 30,
        ];
        $options = array_merge($defaultOptions, $options);

        return new Client($options);
    }

    /**
     * Log messages
     *
     * @param string  $message
     * @param integer $level
     * @param string  $file
     * @return void
     */
    final public function log($message, $level = NULL, $file = NULL)
    {
        $this->middleware->log($message, $file);
    }

    /**
     * Write exception to log
     *
     * @param Exception $e
     * @return void
     */
    final public function logException(Exception $e)
    {
        $this->middleware->logException($e);
    }

    /**
     * Retrieve OAuth url
     *
     * @param array $params
     * @return string
     */
    final public function oauthGetUrl($params = array())
    {
        $params = array_merge(
            $params,
            ['plugin' => $this->code]
        );
        return $this->_getBaseUrl().'oauth.php?'.http_build_query($params, '', '&');
    }

    /**
     * Retrieve webhook url
     *
     * @param string|null $method
     * @return string
     */
    final public function getWebhookUrl($method = NULL)
    {
        $secretKey = $this->middleware->getConfig('middleware/api/secret_key');
        $params = [
            'plugin' => $this->code,
            'secret_key' => $secretKey,
        ];
        if ($method) {
            $params['method'] = $method;
        }
        return $this->_getBaseUrl().'webhook.php?'.http_build_query($params, '', '&');
    }

    /**
     * Retrieve callback url
     *
     * @param string $method
     * @return string
     * @throws Exception
     */
    final public function getCallbackUrl($method)
    {
        if ( ! $this->getPluginInfo('routes/'.$method)) {
            throw new Exception(sprintf('There is no route defined for %s in %s', $method, $this->code));
        }
        $params = [
            'plugin' => $this->code,
            'method' => $method,
            'secret_key' => $this->middleware->getConfig('middleware/api/secret_key'),
        ];
        return $this->_getBaseUrl().'callback.php?'.http_build_query($params, '', '&');
    }

    /**
     * @param Exception $exception
     * @param string    $rawData
     * @param bool      $canNotify
     * @param string|null $prefix
     */
    final public function reportError(Exception $exception, $rawData, $canNotify, $prefix = NULL)
    {
        // Wrap system exceptions
        if ( ! $exception instanceof Plugin_Exception) {
            $exception = new Plugin_Exception('Unexpected plugin error.', 0, $exception);
        }

        $notifyMsg = $canNotify ? 'Notified' : 'Not Notified';
        $prefix = $prefix ?: '-';
        $this->log("$prefix: {$exception->getMessage()} - $notifyMsg - Data:\n$rawData", self::ERR, 'errors.log');
    }

    /**
     * @param string $rawData
     */
    final public function resolveError($rawData)
    {
        $this->log("Resolved errors for data: $rawData", self::ERR, 'errors.log');
    }

    /**
     * Add event to the plugin queue if the queue is enabled
     *
     * @param string $method
     * @param array $data
     * @param null|int $delayTime - in seconds
     * @return void
     */
    final public function addEvent($method, array $data, $delayTime = NULL)
    {
        // TODO
    }

    /**
     * Get a locking object using a safely name-spaced key
     *
     * @param string $key
     * @return Plugin_Lock
     * @throws Plugin_Exception
     */
    final public function getLock($key)
    {
        static $locks = [];
        if (strlen($key) > 100 || preg_match('/[^a-zA-Z0-9_-]/', $key)) {
            $key = md5($key);
        }
        if ( ! isset($locks[$key])) {
            $lock = new Plugin_Lock("{$this->code}-$key");
            $lock->_setLockFilePathPrefix($this->middleware->getLockFilePath(''));
            $locks[$key] = $lock;
        }
        return $locks[$key];
    }

    /**
     * Apply filter scripts
     *
     * @param string $snippet - A snippet of Javascript code.
     * @param array $arguments - A PHP hash with the keys being names of arguments and values being JSON-serializable values which will be converted to Javascript objects.
     * @param string $returnArg - The named argument which should be returned
     * @return array
     * @throws Mage_Core_Exception
     */
    final public function applyScript($snippet, $arguments, $returnArg)
    {
        // Do nothing, only supported in production
        return $arguments[$returnArg];
    }

    /**
     * Apply filter scripts specifically for orders (adds product data to order object)
     *
     * @param string $snippet - A snippet of Javascript code.
     * @param array $newOrderData
     * @param array $arguments - A PHP hash with the keys being names of additional arguments and values being JSON-serializable values which will be converted to Javascript objects.
     * @return array
     * @throws Mage_Core_Exception
     */
    final public function applyScriptForOrder($snippet, $newOrderData, $arguments)
    {
        // Do nothing, only supported in production
        return $newOrderData;
    }

    /**
     * @param string $key
     * @return array|null|string
     */
    final public function loadCache($key)
    {
        return $this->middleware->loadCache($key);
    }

    /**
     * @param string $key
     * @return int|null
     */
    final public function cacheTimestamp($key)
    {
        return $this->middleware->cacheTimestamp($key);
    }

    /**
     * @param string $key
     * @param string $data
     * @param bool|int $lifeTime
     * @throws Exception
     */
    final public function saveCache($key, $data, $lifeTime = FALSE)
    {
        $this->middleware->saveCache($key, $data, $lifeTime);
    }

    /**
     * Remove Cache matching key
     * @param $key
     */
    final public function removeCache($key)
    {
        $this->middleware->removeCache($key);
    }

    /*
     * DO NOT USE METHODS DECLARED BELOW THIS LINE
     */

    /**
     * Respond to test:ping event from user clicking "Send Test Event"
     */
    final public function respondTestPing()
    {
        echo "Pong.\n";
    }

    /**
     * @param \Middleware $middleware
     */
    final public function _setMiddleware(\Middleware $middleware)
    {
        $this->middleware = $middleware;
    }

    /**
     * @param null|bool $isDebug
     * @return bool
     */
    final public function isDebug($isDebug = NULL)
    {
        $result = $this->_isDebug;
        if ( ! is_null($isDebug)) {
            $this->_isDebug = (bool) $isDebug;
        }
        return $result;
    }

    /**
     * Retrieve instance of the JSON client
     *
     * @return Middleware_JsonClient
     * @throws Exception
     */
    final private function _getClient()
    {
        if ( ! $this->_client) {
            $this->_client = new Middleware_JsonClient(
                array(
                    'base_url'  => $this->middleware->getConfig('middleware/api/base_url'),
                    'login'     => $this->middleware->getConfig('middleware/api/login'),
                    'password'  => $this->middleware->getConfig('middleware/api/password'),
                    'debug'     => $this->isDebug(),
                ), array(
                    'timeout'   => 20,
                    'useragent' => 'ShipStreamMiddleware/1.0 ('.$this->code.')',
                    'keepalive' => TRUE,
                ),
                $this->middleware);
        }
        return $this->_client;
    }

    /**
     * Retrieve base url
     *
     * @return string Example: "http://example.com/"
     * @throws Exception
     */
    final private function _getBaseUrl()
    {
        $baseUrl = trim($this->middleware->getConfig('middleware/system/base_url'));
        if ( ! $baseUrl) {
            throw new Exception('The base url is not configured (middleware/system/base_url).');
        }
        $baseUrl .= substr($baseUrl, -1) != '/' ? '/' : '';
        return $baseUrl;
    }
}
