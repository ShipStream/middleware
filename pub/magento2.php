<?php
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
require __DIR__.'/../app/bootstrap.php';

if (isset($_SERVER['PATH_INFO']) && preg_match_all('/\w+\/\w+/', $_SERVER['PATH_INFO'], $matches)) {
    foreach ($matches[0] as $data) {
        list($key, $value) = explode('/', $data);
        if ( ! isset($_GET[$key])) {
            $_GET[$key] = $value;
        }
    }
}

$debug = ! empty($_GET['debug']);
$action = $_GET['action'] ?? NULL;

try {
    $plugin = $_GET['plugin'] ?? NULL;
    if ( ! $plugin) {
        throw new Exception('Plugin not specified.', 400);
    }
    $consumerKey = $_REQUEST['oauth_consumer_key'] ?? NULL;
    if (empty($consumerKey)) {
        throw new Exception('oauth_consumer_key not specified.', 400);
    }
    $middleware = new Middleware($plugin, $debug);
    switch ($action) {
        case 'activate':
            $middleware->saveCache('magento2_'.$consumerKey, $_POST, 3600);
            $middleware->log('Magento 2 OAuth data saved.');
            echo 'OK';
            break;
        case 'authenticate':
            $oauthData = $middleware->loadCache('magento2_'.$consumerKey);
            if (empty($oauthData)) {
                throw new Exception('OAuth data not found.', 400);
            }
            $middleware->oauthHandleRedirect([
                'oauth_consumer_key' => $oauthData['oauth_consumer_key'],
                'oauth_consumer_secret' => $oauthData['oauth_consumer_secret'],
                'oauth_verifier' => $oauthData['oauth_verifier'],
            ]);
            header("Location: {$_REQUEST['success_call_back']}");
            $middleware->log('Magento 2 OAuth completed.');
            break;
    }
} catch (Throwable $e) {
    if (empty($middleware)) {
        error_log($e->getMessage());
    } else {
        $middleware->logException($e);
    }
    http_response_code(400);
    echo $e->getMessage();
}