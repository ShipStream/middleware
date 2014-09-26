<?php
/**
 * Middleware JSON Client
 */
class Middleware_JsonClient extends Zend_Http_Client
{
    const DEBUG_LOG = 'requests.log';

    const ERROR_SESSION_EXPIRED = 5;

    /** @var null|string */
    protected $_sessionId;

    /** @var array */
    protected $_config = array();
    protected $_log, $_saveCache, $_loadCache;

    /**
     * @param array $config
     * @param array|null $clientConfig
     * @param object $framework (an object that has public methods: log, saveCache, loadCache)
     * @throws Exception
     */
    public function __construct(array $config, $clientConfig, $framework)
    {
        foreach (array('base_url', 'login', 'password') as $key) {
            if (empty($config[$key])) {
                throw new Exception(sprintf('Configuration parameter \'%s\' is required.', $key));
            }
        }

        $this->_config = array_merge(array(
            'debug' => FALSE,
        ), $config);

        // Setup framework methods (logging/caching)
        if (is_object($framework)) {
            if (is_callable(array($framework, 'log'))) {
                $this->_log = array($framework, 'log');
            } else {
                $this->_log = function($message, $destination){};
            }
            if (is_callable(array($framework, 'saveCache')) && is_callable(array($framework, 'loadCache'))) {
                $this->_saveCache = array($framework, 'saveCache');
                $this->_loadCache = array($framework, 'loadCache');
            } else {
                $this->_saveCache = function($key, $value, $lifetime) {};
                $this->_loadCache = function($key) { return NULL; };
            }
        }

        parent::__construct($this->_config['base_url'], $clientConfig);

        if ($this->_config['debug']) {
            $this->setCookie('XDEBUG_SESSION','PHPSTORM');
        }
    }

    /**
     * @param string $sessionId
     */
    public function setSessionId($sessionId)
    {
        $this->_sessionId = $sessionId;
    }

    /**
     * Resets parameters after the request for the next one.
     *
     * @param  string $method
     * @throws Exception
     * @return  Zend_Http_Response
     */
    public function request($method = null)
    {
        try {
            $response = parent::request($method);
            $this->resetParameters();
        } catch(Exception $e) {
            $this->resetParameters();
            throw $e;
        }
        return $response;
    }

    /**
     * Wrapper for "call" method
     *
     * @param string $method
     * @param array  $args
     * @param bool   $canRetry
     * @throws Exception
     * @return mixed
     */
    public function call($method, $args = array(), $canRetry = TRUE)
    {
        if ($method != 'login' && ! $this->_sessionId) {
            $this->_login(TRUE);
        }
        $requestData = array(
            'jsonrpc' => '2.0',
            'id' => uniqid(),
        );
        if ($method == 'login') {
            $requestData['method'] = 'login';
            $requestData['params'] = $args;
        } else {
            $requestData['method'] = 'call';
            $requestData['params'] = array($this->_sessionId, $method, $args);
        }
        $requestData = json_encode($requestData);

        $this->setHeaders('Content-Type', 'application/json');
        $this->setHeaders('Accept', 'application/json');
        $this->setRawData($requestData);

        if ($this->_config['debug']) {
            $response = $this->request('POST');
            call_user_func($this->_log, "\n>>>>>>>>> $method >>>>>>>>>\n".$this->getLastRequest()."\n", self::DEBUG_LOG);
            call_user_func($this->_log, "\n<<<<<<<<< $method <<<<<<<<<\n".$response->getHeadersAsString(TRUE, "\n")."\n".$response->getBody()."\n", self::DEBUG_LOG);
        } else {
            $response = $this->request('POST');
        }

        if ($response->isSuccessful()) {
            $return = json_decode($response->getBody(), TRUE);
            if ($return === NULL || ! array_key_exists('result', $return)) {
                throw new Exception('Invalid response: '.$response->getBody());
            }
            if (isset($return['error'])) {
                $code = ($return['error']['code'] * -1) - 32000;

                // Login and retry if session is expired
                if ($code == self::ERROR_SESSION_EXPIRED) {
                    $this->_sessionId = FALSE;
                    if ($canRetry) {
                        $this->_login(FALSE);
                        return $this->call($method, $args, FALSE);
                    }
                }

                throw new Exception("($code) {$return['error']['message']}", $code);
            }
            return $return['result'];
        } else {
            throw new Exception("Response:\n".$response->asString());
        }
    }

    /**
     * @param bool $canRetry
     * @return Middleware_JsonClient
     */
    protected function _login($canRetry)
    {
        if ( ! $this->_sessionId) {
            $cacheKey = md5("sessionId-{$this->_config['base_url']}-{$this->_config['login']}");
            if ($this->_sessionId === NULL) {
                $this->_sessionId = call_user_func($this->_loadCache, $cacheKey);
            }
            if ( ! $this->_sessionId) {
                $this->_sessionId = $this->call('login', array($this->_config['login'], $this->_config['password']), $canRetry);
                call_user_func($this->_saveCache, $cacheKey, $this->_sessionId, 3600);
            }
        }
        return $this;
    }
}
