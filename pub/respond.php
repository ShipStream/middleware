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
    $middleware = new Middleware($plugin, $debug);

    $query = $_GET;
    unset($query['debug'], $query['plugin']);
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (preg_match('/^HTTP_(.*)$/', $key, $matches)) {
            $headers[$matches[1]] = $value;
        }
    }
    unset($headers['HOST'], $headers['X_FORWARDED_FOR']);
    $json = file_get_contents('php://input');
    $data = (array) json_decode($json, TRUE);
    if (JSON_ERROR_NONE !== json_last_error() || ! is_array($data)) {
        throw new Exception(sprintf('JSON decode error code: %d. Content: %s.', json_last_error(), $json), 400);
    }

    $topic = isset($data['topic']) ? strval($data['topic']) : NULL;
    if ( ! $topic) {
        throw new Exception('Topic not specified.', 400);
    }
    $message = isset($data['message']) ? (array) $data['message'] : NULL;
    if ( ! $message) {
        throw new Exception('Message not specified.', 400);
    }

    $middleware->respond($topic, $message);

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
