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
     * @param $query
     * @param $headers
     * @param $data
     * @return bool
     */
    function handleWebhook($query, $headers, $data);

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
     * @return mixed
     */
    function getConfig($path);

    /**
     * Log messages
     *
     * @param string  $message
     * @param integer $level
     * @param string  $file
     * @return void
     */
    function log($message, $level = NULL, $file = NULL);

    /**
     * Write exception to log
     *
     * @param Exception $e
     * @return void
     */
    function logException(Exception $e);

}
