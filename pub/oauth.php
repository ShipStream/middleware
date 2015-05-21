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

$debug = ( ! empty($_GET['debug'])) ? TRUE : FALSE;
$action = ( ! empty($_GET['action'])) ? (string)$_GET['action'] : NULL;

try {
    $plugin = isset($_GET['plugin']) ? $_GET['plugin'] : NULL;
    if ( ! $plugin) {
        throw new Exception('Plugin not specified.', 400);
    }
    $middleware = new Middleware($plugin, $debug);

    $query = $_GET;
    unset($query['debug'], $query['plugin'], $query['action']);

    $redirect = FALSE;
    switch ($action) {
        case 'redirect':
            $middleware->oauthHandleRedirect($query);
            $redirect = TRUE;
            break;
        case 'disconnect':
            $middleware->oauthDisconnect($query);
            $redirect = TRUE;
            break;
    }

    if ($redirect && ! headers_sent()) {
        header('Location: '.$middleware->oauthGetUrl());
        exit;
    }

    echo $middleware->renderPage('oauth_status.phtml');

} catch (Exception $e) {
    if ($debug) {
        if (empty($middleware)) {
            error_log($e->getMessage());
        } else {
            $middleware->log("{$e->getCode()} {$e->getMessage()}");
        }
    }
    echo $e->getMessage();
}