<?php

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
require __DIR__.'/../app/bootstrap.php';

$debug = FALSE;
if ( ! empty($_GET['debug'])) {
    $debug = TRUE;
}

try {
    $plugin = isset($_GET['plugin']) ? $_GET['plugin'] : NULL;
    if ( ! $plugin) {
        throw new Exception('Plugin not specified.', 400);
    }
    $method = isset($_GET['method']) ? $_GET['method'] : NULL;
    if ( ! $method) {
        throw new Exception('Method not specified.', 400);
    }

    $middleware = new Middleware($plugin, $debug);

    $query = $_GET;
    unset($query['debug'], $query['plugin'], $query['method']);
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (preg_match('/^HTTP_(.*)$/', $key, $matches)) {
            $headers[$matches[1]] = $value;
        }
    }
    unset($headers['HOST'], $headers['X_FORWARDED_FOR']);
    $data = file_get_contents('php://input');

    if ($debug) {
        $middleware->log(sprintf('Received callback for %s/%s', $plugin, $method));
    }
    $middleware->callbackController($method, $query, $headers, $data);
} catch (Exception $e) {
    if (empty($middleware)) {
        error_log($e->getMessage());
    } else {
        $middleware->logException($e);
    }
    http_response_code($e->getCode() ?: 500);
}
