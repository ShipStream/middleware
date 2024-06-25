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

    const PCD_TYPE_NAME = 'name';
    const PCD_TYPE_COMPANY = 'company';
    const PCD_TYPE_STREET = 'street';
    const PCD_TYPE_TELEPHONE = 'telephone';
    const PCD_TYPE_EMAIL = 'email';

    const DOCUMENT_FORMAT_ZPL = 'zpl';
    const DOCUMENT_FORMAT_PDF = 'pdf';

    const STATE_KEY_OAUTH_NONCE = 'oauth_nonce';

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
    final public function __construct(string $code)
    {
        $this->code = $code;
    }

    /**
     * @return string
     */
    final protected function getAppTitle(): string
    {
        return $this->middleware->getConfig('middleware/system/app_title') ?: 'ShipStream';
    }


    /*
     * Abstract methods which may be overridden by plugins
     */

    /**
     * @return bool
     */
    public function hasActivation(): bool
    {
        return FALSE;
    }

    /**
     * Activate the plugin
     *
     * @return string[]
     * @throws Plugin_Exception
     */
    public function activate(): array
    {
        return [];
    }

    /**
     * Deactivate the plugin
     *
     * @return string[]
     * @throws Plugin_Exception
     */
    public function deactivate(): array
    {
        return [];
    }

    /**
     * Reinstall the plugin without doing anything destructive (e.g. can update callback urls).
     *
     * @return array
     * @throws Plugin_Exception
     */
    public function reinstall(): array
    {
        return [];
    }

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

    public function isOauthEnabled(): bool
    {
        return $this->getPluginInfo('info/oauth/enabled') && $this->getConfig('use_oauth');
    }

    /**
     * @param array $request
     * @return void
     */
    public function oauthHandleRedirect($request) {}

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
     * @return void
     * @throws Exception
     */
    public function oauthValidateConfig() {}

    /**
     * @return string[]
     * @throws Plugin_Exception
     */
    public function oauthTest() {}

    /**
     * Return true  if the user should see the OAuth connect button while not connected.
     * Return false if OAuth button should not be displayed (e.g. if using custom API key while in OAuth mode)
     * @return bool
     */
    public function oauthReadyToConnect(): bool
    {
        return TRUE;
    }

    /**
     * @return Plugin_IngestResult
     * @throws Plugin_Exception
     */
    public function ingestPurchaseOrderMessage(Message $message): Plugin_IngestResult
    {
        throw new Plugin_Exception('Unsupported message type.', NULL, NULL, 'Ingest message');
    }

    /**
     * @return Plugin_IngestResult
     * @throws Plugin_Exception
     */
    public function ingestAsnMessage(Message $message): Plugin_IngestResult
    {
        throw new Plugin_Exception('Unsupported message type.', NULL, NULL, 'Ingest message');
    }

    /*
     * Available helper methods which CANNOT be overridden by plugins
     */

    /**
     * Allow a plugin to deactivate itself
     */
    final public function deactivateSelf(): void
    {
        return;
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
     * @param null|string $area
     * @param bool $bypassGateway
     * @return string
     */
    final public function oauthGetRedirectUrl($area = NULL, $bypassGateway = FALSE)
    {
        $params = array_merge(
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
     * @param array $stateData
     * @return array
     */
    final function oauthGetStateData(array $stateData): string
    {
        $nonce = bin2hex(random_bytes(256));
        $this->setState(self::STATE_KEY_OAUTH_NONCE, $nonce);
        $stateData['nonce'] = $nonce;
        return \Firebase\JWT\JWT::urlsafeB64Encode(json_encode($stateData));
    }

    /**
     * @param string $data
     * @return array
     */
    final function oauthDecodeStateData(string $data): array
    {
        if ( ! $data) {
            throw new Plugin_Exception('Missing state data.');
        }
        try {
            $data = json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($data), TRUE, 2, JSON_THROW_ON_ERROR);
        } catch (Exception $e) {
            throw new Plugin_Exception('Invalid state data.');
        }
        if (empty($data['nonce']) || $data['nonce'] !== $this->getState(self::STATE_KEY_OAUTH_NONCE)) {
            throw new Plugin_Exception('Invalid nonce.');
        }
        return $data;
    }

    /**
     * @param string $accessToken
     * @return mixed
     * @throws Exception
     */
    final public function oauthSetTokenData($accessToken)
    {
        return $this->setState('oauth_access_token', $accessToken);
    }

    /**
     * @return string
     * @throws Exception
     */
    final public function oauthGetTokenData()
    {
        return $this->getState('oauth_access_token');
    }

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
     * @throws Plugin_Exception
     */
    final public function call($method, $args = array())
    {
        try {
            return $this->_getClient()->call($method, $args, TRUE);
        } catch (Plugin_Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new Plugin_Exception('Unexpected error: '.$e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @param array|string $data
     * @param int|string|array|stdClass|null $value
     * @param int|string|null $ifEquals - if specified, the value is only updated if the value was previously equal to $ifEquals value
     * @return mixed
     * @throws Plugin_Exception
     */
    final public function setState($data, $value = NULL, $ifEquals = NULL)
    {
        if (is_string($data)) {
            $data = $this->code.'_'.$data;
        } elseif (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$this->code.'_'.$k] = $v;
                unset($data[$k]);
            }
        }
        return $this->call('state.set', [$data, $value, $ifEquals]);
    }

    /**
     * @param array|string $keys
     * @param bool $detailed
     * @return array|string|null
     * @throws Exception
     */
    final public function getState($keys, $detailed = FALSE)
    {
        if (is_string($keys)) {
            $keys = $this->code.'_'.$keys;
        } elseif (is_array($keys)) {
            $keys = array_map(function($key){ return $this->code.'_'.$key; }, $keys);
        }
        $data = $this->call('state.get', [$keys, $detailed]);
        if (is_array($keys) && ! empty($data)) {
            foreach ($data as $k => $v) {
                $_k = preg_replace("/^{$this->code}_/", '', $k);
                $data[$_k] = $v;
                unset($data[$k]);
            }
        }
        return $data;
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
     * @param Exception|string $param1
     * @param array|null $headers
     * @param string|null $prefix
     */
    final public function debugLog($param1, $headers = null, $prefix = '')
    {
        if ($param1 instanceof Exception) {
            $this->log($prefix . $param1->getMessage(), self::DEBUG);
        } else if (is_array($headers)) {
            $_headers = [];
            foreach ($headers as $key => $value) {
                $_headers[] = "$key: $value";
            }
            $this->log(sprintf("%s%s\n\n%s\n", $prefix, implode("\n", $_headers), $param1), self::DEBUG);
        } else {
            $this->log($prefix . $param1 . "\n", self::DEBUG);
        }
    }

    /**
     * @return string
     */
    final public function get3PLName(): string
    {
        return Mage::getDefaultConfig('general/store_information/name');
    }

    /**
     * @return string
     */
    final public function getMerchantName(): string
    {
        return Mage::getWebsiteConfig('general/store_information/name', $this->getWebsiteId());
    }

    final public function getRequestId(): string
    {
        return Mage::registry('logger_request_id');
    }

    /**
     * Returns the base url for all external requests WITHOUT a trailing /
     * @throws Plugin_Exception
     */
    final public function getBaseExternalUrl(bool $skipTest = FALSE): string
    {
        return $this->_getBaseUrl();
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
     * Retrieve callback url, pass NULL to get a url containing a {{method}} placeholder.
     *
     * @param string|null $method
     * @return string
     * @throws Exception
     */
    final public function getCallbackUrl($method)
    {
        if ($method && ! $this->getPluginInfo('routes/'.$method)) {
            throw new Exception(sprintf('There is no route defined for %s in %s', $method, $this->code));
        }
        $params = [
            'plugin' => $this->code,
            'method' => $method ?? '{{method}}',
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
     * @param null|int $executeAt - Unix Timestamp
     * @return void
     */
    final public function addEvent($method, array $data, $executeAt = NULL)
    {
        if (empty($method) || ! is_string($method)) {
            throw new Plugin_Exception('Invalid "method" event argument.');
        }
        if ($executeAt && ( ! $executeAt instanceof DateTimeInterface || ! is_int($executeAt))) {
            throw new Plugin_Exception('Invalid "execute_at" event argument.');
        }
        $this->middleware->addEventQueue([$method, new Varien_Object($data), $executeAt]);
    }

    /**
     * Add multiple events to the plugin queue
     *
     * @param array{method: string, data: array, execute_at: null|int|DateTime} $events
     * @return void
     */
    final public function addEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->addEvent($event['method'], $event['data'], $event['execute_at'] ?? NULL);
        }
    }

    /**
     * Call an event immediately
     *
     * @param string $method
     * @param array $data
     * @return void
     */
    final public function callEvent(string $method, array $data): void
    {
        $this->$method($data);
    }

    /**
     * Get a locking object using a safely name-spaced key
     *
     * @param string $key
     * @return Plugin_Lock
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
     * @param int $messageId
     * @return Message|null
     */
    final public function getMessage(int $messageId): ?Message
    {
        throw new Exception('Not implemented.');
    }

    /**
     * @param int $orderEntityId
     * @return MWE_EDI_Model_Resource_Message_Collection|MWE_EDI_Model_Message[]
     */
    final public function getOrderMessages(int $orderEntityId): ?MWE_EDI_Model_Resource_Message_Collection
    {
        throw new Exception('Not implemented.');
    }

    /**
     * @return Document
     */
    final public function createDocument(): Document
    {
        throw new Exception('Not implemented.');
    }

    /**
     * Queue an EDI message for ingest
     *
     * @param MWE_EDI_Model_Message $message
     * @return void
     */
    public function queueIngestMessage(MWE_EDI_Model_Message $message): void
    {
        throw new Exception('Not implemented.');
    }

    /**
     * Ingest an EDI message
     *
     * @param MWE_EDI_Model_Message $message
     * @return void
     * @throws Plugin_Exception
     */
    public function ingestMessage(MWE_EDI_Model_Message $message): void
    {
        throw new Exception('Not implemented.');
    }

    /**
     * Queue sending an OrderAck message
     *
     * @param MWE_EDI_Model_Message $purchaseOrderMessage
     * @param string $orderId
     * @return void
     */
    public function queueGenerateOrderAck(MWE_EDI_Model_Message $purchaseOrderMessage, string $orderId): void
    {
        throw new Exception('Not implemented.');
    }

    /**
     * Send an OrderAck message
     *
     * @param MWE_EDI_Model_Message $purchaseOrderMessage
     * @param string $orderId
     * @return void
     * @throws Plugin_Exception
     */
    public function generateOrderAck(MWE_EDI_Model_Message $purchaseOrderMessage, string $orderId): void
    {
        throw new Exception('Not implemented.');
    }

    /**
     * Ingest an EDI message
     *
     * @param Varien_Object $data
     * @return void
     * @throws Exception
     * @throws Plugin_Exception
     */
    final public function ingestMessageEvent(Varien_Object $data): void
    {
        throw new Exception('Not implemented.');
    }

    /**
     * @param Document $document
     * @return void
     * @throws Plugin_Exception
     * @throws Integration_Exception
     */
    final public function emitDocument(Document $document): void
    {
        throw new Exception('Not implemented.');
    }

    /**
     * @return MWE_Integration_Model_Subscription|null
     */
    final public function getIntegrationSubscription(): ?MWE_Integration_Model_Subscription
    {
        throw new Exception('Not implemented.');
    }

    /**
     * @param int $warehouseId
     * @return DateTimeZone
     */
    final public function getWarehouseTimeZone(int $warehouseId): DateTimeZone
    {
        return new DateTimeZone('America/New_York');
    }

    /**
     * Get warehouse address
     *
     * @param int $warehouseId
     * @return array
     */
    final public function getWarehouseAddress(int $warehouseId): array
    {
        $address = $this->middleware->getConfig("warehouse/id-$warehouseId/address");
        if ( ! $address) {
            throw new Exception('Add address details to app/etc/local.xml');
        }
        $address = array_map('strval', (array)$address);
        foreach (['street', 'city', 'region', 'country', 'postcode'] as $field) {
            if (empty($address[$field])) {
                throw new Exception('Address details missing field: '.$field);
            }
        }
        return $address;
    }

    /**
     * Get SCAC by manifest courier code
     *
     * @param string $manifestCode
     * @return string|null
     */
    final public function getScacByManifestCode(string $manifestCode): ?string
    {
        // Not a full list, just enough for testing
        switch ($manifestCode) {
            case 'ups':
                return 'UPS';
            case 'fedex':
                return 'FDXE';
            case 'usps':
                return 'USPS';
            default:
                return NULL;
        }
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
    private function _getClient()
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
    private function _getBaseUrl()
    {
        $baseUrl = trim($this->middleware->getConfig('middleware/system/base_url'));
        if ( ! $baseUrl) {
            throw new Exception('The base url is not configured (middleware/system/base_url).');
        }
        $baseUrl .= substr($baseUrl, -1) != '/' ? '/' : '';
        return $baseUrl;
    }
}
