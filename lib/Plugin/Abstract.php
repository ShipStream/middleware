<?php

/**
 * Abstract class for a plugin
 */
abstract class Plugin_Abstract implements Plugin_Interface
{
    /** @var string */
    private $code;
    
    /** @var Middleware */
    private $middleware;

    /** @var null|Middleware_JsonClient */
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
     * @param array  $args
     * @return mixed
     */
    final public function call($method, $args = array())
    {
        return $this->_getClient()->call($method, $args, TRUE);
    }

    /**
     * @param array|string $data
     * @param int|string|array|stdClass|null $value
     * @return mixed
     */
    final public function setState($data, $value = NULL)
    {
        return $this->call('state.set', array('data' => $data, 'value' => $value));
    }

    /**
     * @param array|string $keys
     * @return array|string
     */
    final public function getState($keys)
    {
        return $this->call('state.get', array($keys));
    }

    /**
     * Retrieve config value
     *
     * @param string $path
     * @return mixed
     */
    final public function getConfig($path)
    {
        return $this->middleware->getConfig("plugin/{$this->code}/$path");
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


    /*
     * DO NOT USE METHODS DECLARED BELOW THIS LINE
     */

    /**
     * @param Middleware $middleware
     */
    final public function _setMiddleware(Middleware $middleware)
    {
        $this->middleware = $middleware;
    }

    /**
     * @param null|bool $isDebug
     * @return bool
     */
    final public function _isDebug($isDebug = NULL)
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
     */
    final private function _getClient()
    {
        if ( ! $this->_client) {
            $this->_client = new Middleware_JsonClient(
                array(
                    'base_url'  => $this->middleware->getConfig('middleware/api/base_url'),
                    'login'     => $this->middleware->getConfig('middleware/api/login'),
                    'password'  => $this->middleware->getConfig('middleware/api/password'),
                    'debug'     => $this->_isDebug(),
                ), array(
                    'timeout'   => 20,
                    'useragent' => 'Middleware ('.$this->code.')',
                    'keepalive' => TRUE,
                ),
                $this->middleware);
        }
        return $this->_client;
    }
}
