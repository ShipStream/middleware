<?php

/**
 * Interface for a plugin
 */
interface Plugin_Interface
{

    /*
     * Abstract methods which may be overridden by plugins
     */

    /**
     * @param array $query
     * @param array $headers
     * @param string $data
     * @return bool
     */
    function verifyWebhook($query, $headers, $data);

    /**
     * @param array $query
     * @param array $headers
     * @param string $data
     * @return bool
     */
    function handleWebhook($query, $headers, $data);

    /**
     * @param array $request
     * @return void
     */
    function oauthHandleRedirect($request);

    /**
     * @param null|string $area
     * @return string
     */
    function oauthGetRedirectUrl($area = NULL, $bypassGateway = FALSE);

    /**
     * @param array $stateData
     * @return array
     */
    function oauthGetStateData(array $stateData): string;

    /**
     * @param string $data
     * @return array
     */
    function oauthDecodeStateData(string $data): array;

    /**
     * @param string $redirectUrl

     * @return string
     */
    function oauthGetConnectUrl($redirectUrl);

    /**
     * @param array $connectParams
     * @return string
     */
    function oauthGetConnectButton($connectParams = array());

    /**
     * @param array $params
     * @return void
     */
    function oauthDisconnect($params = array());

    /**
     * @param string $accessToken
     * @return mixed
     */
    function oauthSetTokenData($accessToken);

    /**
     * @return string
     */
    function oauthGetTokenData();

    /**
     * @return void
     * @throws Exception
     */
    function oauthValidateConfig();

    /**
     * Retrieve OAuth url
     *
     * @param array $params
     * @return string
     */
    function oauthGetUrl($params = array());

    /**
     * @return mixed
     * @throws Exception
     */
    function oauthTest();

    /**
     * @return bool
     */
    function hasConnectionConfig();

    /**
     * @throws Plugin_Exception
     */
    function connectionDiagnostics(bool $super): array;


    /**
     * True if the plugin needs to take specific actions upon activation (allows Reinstall button)
     *
     * @return bool
     */
    function hasActivation(): bool;

    /**
     * Activate the plugin
     *
     * @return string[]
     * @throws Plugin_Exception
     */
    function activate(): array;

    /**
     * Deactivate the plugin
     *
     * @return string[]
     * @throws Plugin_Exception
     */
     function deactivate(): array;

    /**
     * Reinstall the plugin without doing anything destructive (e.g. can update callback urls).
     *
     * @return array
     * @throws Plugin_Exception
     */
    function reinstall(): array;

    /*
     * Available helper methods which CANNOT be overridden by plugins
     */

    function yieldWebhook();

    /**
     * Wrapper for "call" method
     *
     * @param string $method
     * @param array  $args
     * @return mixed
     */
    function call($method, $args = array());

    /**
     * @param array|string $data
     * @param int|string|array|stdClass|null $value
     * @param int|string|null $ifEquals - if specified, the value is only updated if the value was previously equal to $ifEquals value
     * @return mixed
     */
    function setState($data, $value = NULL, $ifEquals = NULL);

    /**
     * @param array|string $keys
     * @param bool $detailed
     * @return array|string|null
     */
    function getState($keys, $detailed = FALSE);

    /**
     * Retrieve config value
     *
     * @param string $path
     * @return null|string
     */
    function getConfig($path);

    /**
     * Retrieve plugin information value
     *
     * @param string $path
     * @return null|string|array
     */
    function getPluginInfo($path);

    /**
     * @return \DateTimeZone
     */
    function getTimeZone();

    /**
     * @param int $id
     * @return string|null
     */
    function getWarehouseName($id);

    /**
     * Get an instance of GuzzleClient - reuse this instance as much as possible and request a new instance for
     * each unique base_uri.
     *
     * @param array $options
     * @return \GuzzleHttp\Client
     */
    function getHttpClient(array $options);

    /**
     * Log messages
     *
     * @param string  $message
     * @param integer $level
     * @param string  $file
     * @return void
     */
    function log($message, $level = NULL, $file = 'general.log');

    /**
     * Write exception to log
     *
     * @param Exception $e
     * @return void
     */
    function logException(Exception $e);

    /**
     * Check whether to use debug mode
     *
     * @param null|bool $isDebug
     * @return bool
     */
    function isDebug($isDebug = NULL);

    /**
     * Add event to the plugin queue if the queue is enabled
     *
     * @param string $method
     * @param array $data
     * @param null|int $executeAt - Unix Timestamp
     */
    function addEvent($method, array $data, $executeAt = NULL);

    /**
     * Call an event immediately
     *
     * @param string $method
     * @param array $data
     * @return void
     */
    function callEvent(string $method, array $data): void;

    /**
     * Queue an EDI message for ingest
     *
     * @param MWE_EDI_Model_Message $message
     * @return void
     */
    public function queueIngestMessage(MWE_EDI_Model_Message $message): void;

    /**
     * Ingest an EDI message
     *
     * @param MWE_EDI_Model_Message $message
     * @return void
     * @throws Plugin_Exception
     */
    public function ingestMessage(MWE_EDI_Model_Message $message): void;

    /**
     * Queue sending an OrderAck message
     *
     * @param MWE_EDI_Model_Message $purchaseOrderMessage
     * @param string $orderId
     * @return void
     */
    public function queueGenerateOrderAck(MWE_EDI_Model_Message $purchaseOrderMessage, string $orderId): void;

    /**
     * Send an OrderAck message
     *
     * @param MWE_EDI_Model_Message $purchaseOrderMessage
     * @param string $orderId
     * @return void
     * @throws Plugin_Exception
     */
    public function generateOrderAck(MWE_EDI_Model_Message $purchaseOrderMessage, string $orderId): void;

    /**
     * Get a locking object using a safely name-spaced key
     *
     * @param string $key
     * @return Plugin_Lock
     * @throws Plugin_Exception
     */
    function getLock($key);

    /**
     * @param $key
     * @return mixed
     */
    function loadCache($key);

    /**
     * @param string $key
     * @param string $data
     * @param bool|int $lifeTime
     */
    function saveCache($key, $data, $lifeTime = FALSE);

    /**
     * @param $key
     */
    function removeCache($key);
}
