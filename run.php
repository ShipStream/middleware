<?php
if (php_sapi_name() !== 'cli' || ! isset($argv) || ! is_array($argv) || ! isset($argv[0])) {
    die("The file is designed to be used exclusively in the command line.\n");
}

$usage = "
Usage: bin/mwrun <plugin> <method>|--info|--list|--listen [--debug]
  --list                 List all methods that can be invoked from the command line
  --listen               Connect to Redis server for receiving ShipStream events
  --respond-url          Show url for receiving ShipStream events
  --webhook-url          Show url for receiving third-party webhooks
  --callback-url <name>  Show url for receiving callback requests
";

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
require __DIR__.'/app/bootstrap.php';

$debug = (bool)($_ENV['DEBUG'] ?? FALSE);
if ( ! $debug && $argc == 4 && $argv[3] == '--debug') {
    $debug = TRUE;
    array_pop($argv);
}
if ($argc == 2) {
    die($usage);
}

try {
    $plugin = trim(strval($argv[1]));
    $middleware = new Middleware($plugin, $debug);
    if (empty($argv[2])) {
        die($usage);
    }
    $method = trim(strval($argv[2]));
    if ($method === '--respond-url') {
        echo $middleware->getRespondUrl()."\n";
        exit;
    } else if ($method === '--webhook-url') {
        echo $middleware->getWebhookUrl()."\n";
        exit;
    } else if ($method === '--callback-url') {
        if (empty($argv[3])) {
            throw new Exception('Please specify the first argument to getCallbackUrl after "--callback-url"');
        }
        echo $middleware->getCallbackUrl($argv[3])."\n";
        exit;
    } else if ($method === '--listen') {
        while (1) {
            $middleware->subscribe();
            echo "Reconnecting...";
            sleep(3);
        }
    } else if ($method === '--list') {
        $reflection = new ReflectionClass($plugin.'_Plugin');
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (in_array($method->name, ['yieldWebhook', 'getTimeZone', 'oauthGetTokenData', 'oauthTest'])) {
                continue;
            }
            if ( ! $method->getParameters() && ! $method->getReturnType()) {
                echo "$method->name\n    {$method->getDocComment()}\n";
            }
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
