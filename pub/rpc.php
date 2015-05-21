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

    $middleware->callbackController($method, $query, $headers, $data);
} catch (Exception $e) {
    if ($debug) {
        if (empty($middleware)) {
            error_log($e->getMessage());
        } else {
            $middleware->log("{$e->getCode()} {$e->getMessage()}");
        }
    }
    http_response_code($e->getCode() ?: 500);
}
