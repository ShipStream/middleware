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
    function oauthGetRedirectUrl($area = NULL);

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
     * @return mixed
     * @throws Exception
     */
    function oauthTest();

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
     * @return mixed
     */
    function setState($data, $value = NULL);

    /**
     * @param array|string $keys
     * @return array|string
     */
    function getState($keys);

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
     * Retrieve OAuth url
     *
     * @param array $params
     * @return string
     */
    function oauthGetUrl($params = array());

    /**
     * Check whether use debug mode
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
     * @param null|int $delayTime - in seconds
     * @return void
     */
    function addEvent($method, array $data, $delayTime = NULL);

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
