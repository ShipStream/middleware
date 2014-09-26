<?php
if (php_sapi_name() !== 'cli' || ! isset($argv) || ! is_array($argv) || ! isset($argv[0])) {
    die("The file is designed to be used exclusively in the command line.\n");
}

$usage = "Usage: {$argv[0]} <plugin> <method>|--listen [--debug]\n";

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
require __DIR__.'/app/bootstrap.php';

$debug = FALSE;
if ($argc == 4 && $argv[3] == '--debug') {
    $debug = TRUE;
    array_pop($argv);
}

if (count($argv) != 3) {
    die($usage);
}

try {
    $plugin = trim(strval($argv[1]));
    $method = trim(strval($argv[2]));
    $middleware = new Middleware($plugin, $debug);
    if ($method === '--listen') {
        while (1) {
            $middleware->subscribe();
            echo "Reconnecting...";
            sleep(3);
        }
    } else {
        $middleware->run($method);
    }
} catch (Exception $e) {
    if ($debug) {
        echo "$e\n";
    } else {
        echo get_class($e).": {$e->getMessage()}\n";
    }
}
