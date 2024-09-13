<?php
if (php_sapi_name() !== 'cli' || ! isset($argv) || ! is_array($argv) || ! isset($argv[0])) {
    die("The file is designed to be used exclusively in the command line.\n");
}

$usage = "
Usage:

bin/mwrun {command}
  --list-plugins         List all plugins that are detected
  --setup-api            Setup API credentials

bin/mwrun <plugin> <method>|{command} [--debug]
  --list-actions         List all methods that can be invoked from the command line
  --listen               Connect to Redis server for receiving ShipStream events
  --respond-url          Show url for receiving ShipStream events
  --webhook-url          Show url for receiving third-party webhooks
  --callback-url <name>  Show url for receiving callback requests
  --diagnostics          Show connection diagnostics
  --setup-webhook        Setup webhook for plugin
";

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
require __DIR__.'/app/bootstrap.php';

$debug = (bool) getenv('DEBUG');
if ( ! $debug && $argc == 4 && $argv[3] == '--debug') {
    $debug = TRUE;
    array_pop($argv);
}
if ($argc <= 1) {
    die($usage);
}

try {
    if ($argv[1] === '--list-plugins') {
        require 'Varien/Simplexml/Element.php';
        printf("%40s%40s\n", 'Plugin Code', 'Plugin Name');
        printf("%40s%40s\n", '-----------', '-----------');
        foreach (glob(__DIR__.'/app/etc/modules/*.xml') as $file) {
            $moduleConfig = simplexml_load_file($file, 'Varien_Simplexml_Element'); /* @var $moduleConfig Varien_Simplexml_Element */
            foreach ($moduleConfig->modules->children() as $module) {
                $pluginCode = $module->getName();
                if ($module->active != 'true') {
                    continue;
                }
                $pluginFile = __DIR__.'/app/code/community/'.str_replace('_', '/', $pluginCode).'/etc/plugin.xml';
                if (file_exists($pluginFile)) {
                    $pluginConfig = simplexml_load_file($pluginFile, 'Varien_Simplexml_Element'); /* @var $pluginConfig Varien_Simplexml_Element */
                    $pluginName = $pluginConfig->{$pluginCode}->info->name;
                    printf("%40s%40s\n", $pluginCode, $pluginName);
                } else {
                    echo "$pluginFile not found for $pluginCode\n";
                }
            }
        }
        exit;
    } else if ($argv[1] === '--setup-api') {
        $configFile = __DIR__ . '/app/etc/local.xml';
        if ( ! file_exists($configFile)) {
            echo "Config file $configFile not found.\n";
            exit (1);
        }
        if ( ! is_writable($configFile)) {
            echo "Config file $configFile is not writable. Example: `sudo chmod 666 app/etc/local.xml`\n";
            exit (1);
        }
        echo "What is your ShipStream instance base url?\n";
        $baseUrl = rtrim(trim(fgets(STDIN)), '/');
        $baseUrl = preg_replace('#/api/jsonrpc/?$#', '', $baseUrl);
        // Expected response: '{"error":{"code":-32600,"message":"Invalid Request","data":"No method specified."},"id":null}'
        try {
            $client = new \GuzzleHttp\Client(['base_uri' => $baseUrl]);
            $response = $client->post('api/jsonrpc/');
            $data = json_decode($response->getBody()->getContents(), FALSE, 512, JSON_THROW_ON_ERROR);
            if (!isset($data->error->code) || $data->error->code !== -32600) {
                throw new Exception('Invalid response from ShipStream. Check that your base url is correct.');
            }
        } catch (Exception $e) {
            echo "Failed to validate ShipStream base url: {$e->getMessage()}\n";
            exit (1);
        }
        echo "Please go to $baseUrl/admin/api_user/new/ to create a new Merchant API User if needed.\n";
        echo "What is your ShipStream API username?\n";
        $username = trim(fgets(STDIN));
        echo "What is your ShipStream API password?\n";
        $password = trim(fgets(STDIN));
        try {
            $response = $client->post('api/jsonrpc/', [
                'json' => [
                    'jsonrpc' => '2.0',
                    'method' => 'login',
                    'params' => [$username, $password],
                    'id' => 1,
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), TRUE, 512, JSON_THROW_ON_ERROR);
            if (!empty($data['error']['message'])) {
                throw new Exception('Failed to login to ShipStream: ' . $data['error']['message']);
            }
            if (!isset($data['result']) || !$data['result']) {
                throw new Exception('Invalid response from ShipStream. Check that your username and password are correct.');
            }
        } catch (Exception $e) {
            echo "Failed to login to ShipStream: {$e->getMessage()}\n";
            exit (1);
        }
        $xml = simplexml_load_file($configFile);
        $xml->default->middleware->api = $xml->default->middleware->api ?: new SimpleXMLElement('<api/>');
        $xml->default->middleware->api->base_url = "$baseUrl/api/jsonrpc/";
        $xml->default->middleware->api->login = $username;
        $xml->default->middleware->api->password = $password;
        if (!$xml->saveXML($configFile)) {
            echo "Could not write config file. Please check permissions.\n";
            echo "Intended contents of $configFile:\n";
            echo $xml->asXML();
            exit (1);
        }
        echo "API credentials saved to $configFile\n";
        exit;
    }
    if ($argc <= 2) {
        die($usage);
    }
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
        echo $middleware->getCallbackUrl($argv[3]) . "\n";
        exit;
    } else if ($method === '--diagnostics') {
        printf("\nDiagnostic information for %s reported at %s\n", $plugin, date('c'));
        echo "---------------------------------------------------------------------------------\n";
        if ($middleware->hasConnection()) {
            echo "Connection is configured.\n";
        } else {
            echo "Connection is not configured.\n";
        }
        try {
            $result = $middleware->diagnostics();
            foreach ($result as $line) {
                echo "$line\n";
            }
        } catch (Plugin_Exception $e) {
            echo "Plugin Exception: {$e->getMessage()}\n";
        } catch (Throwable $e) {
            echo "Internal error!! This should not happen, all exceptions must be parents of Plugin_Exception.\n";
            echo "$e\n";
        }
        echo "---------------------------------------------------------------------------------\n";
    } else if ($method === '--listen') {
        while (1) {
            $middleware->subscribe();
            echo "Reconnecting...";
            sleep(3);
        }
    } else if ($method === '--list-actions') {
        $reflection = new ReflectionClass($plugin . '_Plugin');
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (in_array($method->name, ['yieldWebhook', 'getTimeZone', 'oauthGetTokenData', 'oauthTest'])) {
                continue;
            }
            if ( ! $method->getParameters() && ! $method->getReturnType()) {
                $lines = explode("\n", $method->getDocComment());
                $lines = array_filter($lines, function($line) {
                    return ! preg_match('#\s*(/\*\*|\*/|\* @.*|\*)\s*$#', $line);
                });
                echo "$method->name\n".implode("\n", $lines)."\n";
            }
        }
    } else if ($method === '--crontab') {
        echo "Running crontab for {$argv[3]}...\n";
        echo "NOT YET IMPLEMENTED!! Please run your cron tasks using the method name directly.\n";
        // TODO
    } else if ($method === '--setup-webhook') {
        $configFile = __DIR__ . '/app/etc/local.xml';
        if ( ! file_exists($configFile)) {
            echo "Config file $configFile not found.\n";
            exit (1);
        }
        if ( ! is_writable($configFile)) {
            echo "Config file $configFile is not writable. Example: `sudo chmod 666 app/etc/local.xml`\n";
            exit (1);
        }
        // Generate a secret key and create a webhook for the methods required by the plugin
        $topics = [];
        $eventsNode = $middleware->getConfig('plugin/' . $plugin . '/events', FALSE);
        if ($eventsNode) {
            foreach ($eventsNode->children() as $entityType => $event) {
                foreach ($event->children() as $eventName => $enabled) {
                    if ($enabled) {
                        $topics[] = "$entityType:$eventName";
                    }
                }
            }
        }
        if (empty($topics)) {
            echo "No events are enabled for this plugin.\n";
            exit (1);
        }
        if ( ! $middleware->getConfig('middleware/api/base_url')) {
            echo "Connection is not configured. Please run bin/mwrun --setup-api\n";
            exit (1);
        }
        $secretKey = $middleware->getConfig('middleware/api/secret_key');
        if ( ! $secretKey) {
            $secretKey = bin2hex(random_bytes(16));
            $xml = simplexml_load_file($configFile);
            $xml->default->middleware->api->secret_key = $secretKey;
            if (file_put_contents($configFile, $xml->asXML())) {
                echo "Secret key saved to $configFile\n";
            } else {
                echo "Could not write config file. Please check permissions.\n";
                echo "Intended contents of $configFile:\n";
                echo $xml->asXML();
                exit (1);
            }
        }
        $localUrl = trim($middleware->getConfig('middleware/system/base_url'));
        try {
            $client = new \GuzzleHttp\Client(['base_uri' => $localUrl]);
            $client->get('hello.php');
        } catch (Exception $e) {
            echo "Failed to connect to middleware environment using public url $localUrl\nError: {$e->getMessage()}\n";
            echo "Make sure your HTTPS tunnel is running and the public url is correct in app/etc/local.xml at default/middleware/system/base_url.\n";
            exit (1);
        }

        $webhookUrl = $middleware->getRespondUrl();

        $client = new \GuzzleHttp\Client([
            'base_uri' => $middleware->getConfig('middleware/api/base_url'),
            'auth' => [$middleware->getConfig('middleware/api/login'), $middleware->getConfig('middleware/api/password')],
        ]);

        $response = $client->post('', [
            'json' => [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    null,
                    'webhook.list',
                    [],
                ],
                'id' => 1,
            ],
        ]);
        $data = json_decode($response->getBody()->getContents(), TRUE, 512, JSON_THROW_ON_ERROR);
        if (!empty($data['error']['message'])) {
            throw new Exception('Failed to create webhook: ' . $data['error']['message']);
        }
        foreach ($data['result'] as $webhook) {
            if ($webhook['url'] === $webhookUrl || $webhook['extra_headers'] === "X-Middleware-Id: $secretKey") {
                $client->post('', [
                    'json' => [
                        'jsonrpc' => '2.0',
                        'method' => 'call',
                        'params' => [
                            null,
                            'webhook.delete',
                            [
                                $webhook['webhook_id'],
                            ],
                        ],
                        'id' => 2,
                    ],
                ]);
                echo "Deleted existing webhook for {$webhook['url']}\n";
            }
        }
        $response = $client->post('', [
            'json' => [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    null,
                    'webhook.create',
                    [
                        [
                            'is_active' => true,
                            'url' => $webhookUrl,
                            'topics' => $topics,
                            'secret_key' => $secretKey,
                            'extra_headers' => "X-Middleware-Id: $secretKey",
                        ]
                    ],
                ],
                'id' => 2,
            ],
        ]);
        $data = json_decode($response->getBody()->getContents(), TRUE, 512, JSON_THROW_ON_ERROR);
        if (!empty($data['error']['message'])) {
            throw new Exception('Failed to create webhook: ' . $data['error']['message']);
        }

        echo "Webhook created for $webhookUrl\n";
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
