ShipStream Merchant API Middleware
========

The ShipStream Merchant API Middleware is an abstracted and lightweight version of ShipStream's production
environment. With it, you can develop and test plugins destined to become integrated ShipStream WMS plugins,
or use it as a standalone "middle-man" app between your systems and ShipStream.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Developer Guide](#developer-guide)
  - [Plugin Skeleton](#plugin-skeleton)
  - [Plugin Information](#plugin-information)
  - [Plugin Configuration](#plugin-configuration)
  - [ShipStream API Calls](#shipstream-api-calls)
  - [HTTP Client](#http-client)
  - [State Management](#state-management)
  - [Manual Actions](#manual-actions)
  - [Cron Tasks](#cron-tasks)
  - [ShipStream Events](#shipstream-events)
  - [Third-party Webhooks](#third-party-webhooks)
  - [Third-party Remote Callbacks](#third-party-remote-callbacks)
  - [Error Handling and Reporting](#error-handling-and-reporting)
  - [Job Queue](#job-queue)
  - [Global Locking](#global-locking)
  - [Logging](#logging)
  - [Caching](#caching)
  - [OAuth](#oauth)
  - [Composer Dependencies](#composer-dependencies)
- [Merchant API Documentation](https://docs.shipstream.io) (external link)

Features
--------
ShipStream plugins support the following features which work exactly the same in both the middleware environment (this repository)
and the production environment (ShipStream WMS).

* Interact with ShipStream's [Merchant API](https://docs.shipstream.io) either as an embedded plugin or over https
* Simple configuration
* Run actions on demand
* Run actions periodically via cron jobs
* Respond to ShipStream events in real time (via Webhooks if using the middleware environment)
* Respond to third-party webhook events and remote callbacks
* Queue tasks to be executed in the background with error reporting and user-directed retries
* Use state management and caching for ease of use and efficiency
* Global locking to solve tricky race conditions
* Perform OAuth authentication via command line or browser

A plugin that is installed on the production environment can be configured by the user via the Merchant Panel as a
"Subscription" or run in the middleware environment with a very simple installation using Docker Compose.
Either way, the functionality of the plugin should be identical. While the "middleware" is intended to be mainly a
development environment, you can just as well use it as the basis for your integration or as a standalone app on
your own hosting environment.

### Subscriptions

In the production environment a plugin runs in the context of a single merchant after being added as a "Subscription"
via the ShipStream Admin or Client user interface. A merchant can have multiple subscriptions and the configuration and
state data is not shared between subscriptions even if they are used by the same merchant or use the same key names
(state keys are namespaced to each subscription to avoid conflicts). Keep this in mind while developing the plugin as
there may be legitimate use cases for a single merchant having multiple subscriptions installed, such as if they use
multiple brands names and have a separate shopping cart for each brand name.

Requirements
------------

* The supported platform for developing ShipStream plugins is PHP 7.4 on Docker (container build files provided).
* A publicly accessible URL is required if your plugin receives third-party webhooks or responds to events from
  ShipStream via ShipStream's webhooks.

### Windows

Developing on Windows requires [WSL2 (Windows Subsystem for Linux)](https://docs.microsoft.com/en-us/windows/wsl/install-win10)
and [Docker Desktop for Windows](https://docs.docker.com/docker-for-windows/install/).

### Mac

Developing on Mac requires [Docker Desktop for Mac](https://docs.docker.com/docker-for-mac/install/).


Installation
------------

1. Clone this repository to a new directory and update files permissions and install dependencies:
   ```
   $ git clone https://github.com/shipstream/middleware.git
   $ cd middleware
   $ chmod go+rwX tmp logs
   $ bin/update
   ```
   
2. Copy and edit the sample config file to add your configuration:
   ```
   $ cp app/etc/local.sample.xml app/etc/local.xml
   ```
   Example:
   ```xml
   <?xml version="1.0"?>
   <config>
     <default>
       <middleware>
         <system>
           <base_url>http://localhost/</base_url>
           <log>stdout</log>
         </system>
         <api>
           <base_url>https://example.shipstream.app/api/jsonrpc</base_url>
           <login>{api_username}</login>
           <password>{api_password}</password>
         </api>
       </middleware>
     </default>
   </config>
   ```
   
3. Clone the `ShipStream_Test` plugin and run the `update_ip` method to confirm a successful setup!
   ```
   $ bin/modman init
   $ bin/modman clone https://github.com/shipstream/plugin-test.git
   $ bin/mwrun ShipStream_Test update_ip
   Creating middleware_cli_run ... done
   Agent 007's IP is x.x.x.x, last updated at 2020-01-19T14:41:23+00:00.
   ```

### Advanced

You can use a `.env` file in the root of the project to set some configuration options:

- `DEBUG` Enable for HTTP request logging and other debug features (default is disabled).
- `HOST_PORT` Choose a different port to expose (default is 80).

Developer Guide
===============

The easiest way to start your own plugin is to fork the [`ShipStream_Test`](https://github.com/shipstream/plugin-test)
project which you would have cloned in step 3 and then rename and edit it as needed.

## Debug Mode

Debug mode can be enabled in one of the following ways:

- Use the DEBUG environment variable when running a command
  ```
  $ DEBUG=1 bin/mwrun ShipStream_Test update_ip
  ```
- Pass the `--debug` argument at the end of a command
  ```
  $ bin/mwrun ShipStream_Test update_ip --debug
  ```
- Set the DEBUG environment variable in the `.env` file
  ```
  $ echo 'DEBUG=1' >> .env
  ```

## Plugin Skeleton

The minimal required file structure from the root of the middleware directory is as follows:

* app/code/community/{COMPANY_NAME}/{MODULE_NAME}
  * etc
    * config.xml
    * plugin.xml
  * Plugin.php
* app/etc/modules
  * {COMPANY_NAME}_{MODULE_NAME}.xml
* modman

Additional libraries and files may be added within the module directory as necessary and autoloaded using either
the underscore separated path name convention for autoloading or composer autoloading.

### Plugin Class

The bulk of your plugin logic will exist in a PHP class which extends `Plugin_Abstract` and implements
`Plugin Interface`. These classes expose a common interface allowing the plugin to be transferable between
the middleware and production environments. The file name is `Plugin.php` and the class name follows PSR-1
naming conventions so for example the `Plugin.php` file for the `ShipStream_Test` plugin would exist at
`app/code/community/ShipStream/Test/Plugin.php` and contain the class definition like so:

```php
<?php

class ShipStream_Test_Plugin extends Plugin_Abstract
{
    // ...
}
```

### modman

Rather than mix your plugin files into the middleware environment it is recommended to use `bin/modman`
to symlink the files into the project directories so the `modman` file for the `ShipStream_Test` plugin
may look like this:

```
code                   app/code/community/ShipStream/Test/
ShipStream_Test.xml    app/etc/modules/
```

After modifying the modman file be sure to run the `modman deploy` command to update symlinks:

```
$ bin/modman deploy-all
```

### Register Plugin

The plugin is registered to the production environment using an XML file placed in `app/etc/modules` named after the
plugin namespace. This file is not required for the middleware environment to function.

Example file contents for `app/etc/modules/ShipStream_Test.xml`:

```xml
<?xml version="1.0"?>
<config>
    <modules>
        <ShipStream_Test>
            <codePool>community</codePool>
            <active>true</active>
        </ShipStream_Test>
    </modules>
</config>
```

### config.xml

The `config.xml` is used to set the plugin version and the default configuration values. Here is a `config.xml` example
for the `ShipStream_Test` plugin which sets the version to '0.1' and adds some default configuration values.

```xml
<?xml version="1.0"?>
<config>
    <modules>
        <ShipStream_Test>
            <version>0.1</version>
        </ShipStream_Test>
    </modules>
    <default>
        <plugin>
            <ShipStream_Test>
                <whoami>Your Name</whoami>
                <service_url>http://bot.whatismyipaddress.com</service_url>
                <events>
                    <order>
                        <created>1</created>
                    </order>
                </events>
            </ShipStream_Test>
        </plugin>
    </default>
</config>
```

See the [ShipStream Events](#shipstream-events) section for more on the `<events>` node and [plugin.xml](#pluginxml)
for more on making the configuration accessible to the user.

### plugin.xml

The `plugin.xml` file provides the remaining plugin metadata in the following nodes:

- `<info>` Plugin information and feature flags ([Plugin Information](#plugin-information))
- `<actions>` Buttons that the user can click in the UI to trigger manual actions. ([Plugin Configuration](#plugin-configuration))
- `<config>` Form fields that will be presented to the user for configuring the subscription ([Plugin Configuration](#plugin-configuration))
- `<routes>` Definition of urls which should map to plugin method for receiving external requests
  ([Third-party Remote Callbacks](#third-party-remote-callbacks))
- `<crontab>` Cron tasks which will be automatically run in the production environment ([Cron Tasks](#cron-tasks))

## Plugin Information

The plugin information is defined in the `<info>` node of the `plugin.xml` file under the correct namespace
(e.g. `<SchipStream_Test>`).

The name, author, license and description fields may be presented to the user when creating or editing a subscription.

The `<oauth>` node defines if OAuth is enabled and which config nodes are required to have non-empty values in order
for OAuth authentication to be attempted. See [OAuth](#oauth) for more info.

```xml
<?xml version="1.0" encoding="utf-8"?>
<plugin>
    <ShipStream_Test>
        <info>
            <name>Test Plugin</name>
            <author>ShipStream, LLC</author>
            <license>
                <name>OSL 3.0</name>
                <url>http://opensource.org/licenses/osl-3.0.php</url>
            </license>
            <homepage>https://github.com/shipstream/plugin-test</homepage>
            <description><![CDATA[
                <img src="{asset_base_url}images/logo.png"/>
                <br/>
                This is a <em>barebones</em> example to demonstrate what a plugin looks like.
            ]]></description>
            <oauth>
                <enabled>1</enabled>
                <required_config>whoami</required_config>
            </oauth>
        </info>
        <!-- ... -->
    </ShipStream_Test>
</plugin>
```

The plugin info can be accessed in the PHP plugin code using the `getPluginInfo` method:

```php
$name = $this->getPluginInfo('name');
// $name = 'Test Plugin'
```

However, data that does not have to do with the plugin description or OAuth configuration belongs in the `config.xml`
file (see [Plugin Configuration](#plugin-configuration)).

## Plugin Configuration

The `<default>` node in `config.xml` allows you to organize configuration that has default values but can be also
manipulated by users through the ShipStream user interface.

Configuration that is highly sensitive can be encrypted in storage by defining the value with the "backend_model"
attribute. Note, however that encrypted values will not be visible to users so if the configuration field does not pose
a security threat by being visible to users it may be preferable to leave it unencrypted for convenience to the user.

The user interface for the configuration is defined in `plugin.xml` using matching node names. For example, the `whoami`
config field defined at `config/default/plugin/ShipStream_Test/whoami` in `config.xml` corresponds to
`plugin/ShipStream_Test/config/whoami` in `plugin.xml`.

Each config field can contain the following elements:

- `<label>` The name of the field presented to the user
- `<type>` The type of field presented to the user. Supported types are: 'text', 'url', 'email', 'tel', 'date', 
  'time', 'password', 'obscure', 'textarea', 'date', 'select', 'script'
- `<sort_order>` The sort order of the field relative to other fields (lowest first)
- `<source>` Required only for 'select' type fields, this is the source model for the options which is a class name
  containing a method with a signature like `public function getOptions(Plugin_Abstract $plugin)` which will return
  an array of options (e.g. `return [['label' => 'Zero', 'value' => '0']]`).
- `<comment>` A comment that will appear below the field to give additional context to the user. May contain HTML; use
CDATA to avoid character encoding issues in the XML file.
  
config.xml:

```xml
<?xml version="1.0"?>
<config>
    <default>
        <plugin>
            <ShipStream_Test>
                <whoami>Your Name</whoami>
                <service_url>http://bot.whatismyipaddress.com</service_url>
                <secret_key backend_model="adminhtml/system_config_backend_encrypted"/>
            </ShipStream_Test>
        </plugin>
    </default>
</config>
```

plugin.xml:

```xml
<?xml version="1.0" encoding="utf-8"?>
<plugin>
    <ShipStream_Test>
        <!-- ... -->
        <config>
            <whoami>
                <label>Who Am I</label>
                <type>text</type>
                <sort_order>0</sort_order>
                <comment>Enter a name. Be creative.</comment>
            </whoami>
            <service_url>
                <label>Service Url</label>
                <type>select</type>
                <source>ShipStream_Test_Source_Serviceurl</source>
                <sort_order>10</sort_order>
                <comment>Choose the service to use to discover your IP address.</comment>
            </service_url>
            <secret_key>
                <label>Secret Key</label>
                <type>obscure</type>
                <sort_order>20</sort_order>
                <comment>Just demonstrating an obscured input field.</comment>
            </secret_key>
        </config>
    </ShipStream_Test>
</plugin>
```

## ShipStream API Calls

You may call ShipStream API methods from the plugin using the `call` method so there is no need to deal with
an HTTP client or authentication. In production this will call methods directly and in the middleware environment
there will be a synchronous HTTP request for every invocation but the return values will be the same.

See the **[API Documentation](https://docs.shipstream.io)** for information on specific API methods and their
arguments and responses.

Example:

```php
$inventory = $this->call('inventory.list', 'SKU-A');
// [['sku' => 'SKU-A', 'qty_advertised' => '0.0000', ...]]
```

## HTTP Client

Use the `getHttpClient` method to obtain an instance of `\GuzzleHttp\Client`. This allows the production environment
to perform proper logging and monitoring to ensure optimum functionality.

```php
$client = $this->getHttpClient([
    'base_uri' => 'http://requestbin.net/r/chs4u8vm',
    'auth' => ['username', 'password'],
]);
$response = $client->get(['query' => ['foo' => 'bar']]);
$response->getStatusCode(); // 200
```

The request and response will be logged to `logs/http_client.log` unless 

See the [Guzzle Documentation](https://docs.guzzlephp.org/en/stable/quickstart.html) for more information.

## State Management

ShipStream provides a general-purpose storage mechanism to hold state data like the last time a sync was completed.
Do *not* use this to store a large number of values or values that will be "leaked" over time such as a status for
every order. For example, if your plugin registers a webhook with a third-party you could store the id of the webhook
so that it can be easily updated or deleted later. 

```php
$this->setState('test', array(
    'my_name' => $this->getConfig('whoami'),
    'last_updated' => time(),
));

$data = $this->getState('test');
$this->log("{$data['my_name']} last updated at ".date('c', $data['last_updated']).".");
```

## Manual Actions

Any public plugin method can be run by executing the following command in the command line specifying the plugin
namespace and method name. You can also allow the user to trigger methods manually by defining an "action" node in
the `plugin.xml` file. 

For example, running the following console command is equivalent to the user clicking "Update IP" in the user interface,
both will run the `update_ip` method defined in the PHP class with the proper environment context.

```
$ bin/mwrun ShipStream_Test update_ip
```

plugin.xml:

```xml
<?xml version="1.0" encoding="utf-8"?>
<plugin>
    <ShipStream_Test>
        <!-- ... -->
        <actions>
            <update_ip>
                <label>Update IP</label>
                <comment>Update the IP address stored in the plugin state data.</comment>
            </update_ip>
        </actions>
    </ShipStream_Test>
</plugin>
```

## Cron Tasks

If just hacking on a plugin, you do not need to schedule a crontab task; you can just run the cron task methods
using `mwrun` as any other method. If you want to schedule a crontab task in the production environment you can do
so by defining a task in `plugin.xml`. The available schedules are:

- every_five_minutes
- every_ten_minutes
- every_one_hour
- every_day_midnight
- every_day_morning
- every_day_evening

For example, the task defined in this `plugin.xml` file will run the method
`ShipStream_Test_Plugin::cronImportFulfillments` every ten minutes.

```xml
<?xml version="1.0" encoding="utf-8"?>
<plugin>
    <ShipStream_Test>
        <!-- ... -->
        <crontab>
            <every_ten_minutes>
                <import_fulfillments>cronImportFulfillments</import_fulfillments>
            </every_ten_minutes>
        </crontab>
    </ShipStream_Test>
</plugin>
```

An important consideration when using cron tasks is to ensure that the time between tasks is greater than the time
required to complete the task. Use events to make your integration nearly real-time where possible and use polling
as a fallback.

If you need a cron schedule that is not provided please request it with an explanation for your use-case.

## ShipStream Events

Your plugin can receive immediate notifications from ShipStream with valuable metadata which can make developing an
effective integration much easier and with the end result being much more robust. The events the plugin can receive
are the same as the [Webhook Topics](https://docs.shipstream.io/ref/topics.html) and in fact the middleware environment
uses webhooks to receive these events.

To receive an event for a topic, add the XML elements into your `config.xml` file corresponding to the event topic
you would like to receive. For example, to receive the `order:created` topic, your `config.xml` file would look like this:

```xml
<?xml version="1.0"?>
<config>
    <default>
        <plugin>
            <ShipStream_Test>
                <events>
                    <order>
                        <created>1</created>
                    </order>
                </events>
            </ShipStream_Test>
        </plugin>
    </default>
</config>
```

Create a method in your `Plugin.php` file by camel-casing the topic name and prefixing the method name with 'respond'.

```php
class ShipStream_Test_Plugin extends Plugin_Abstract
{
    /**
     * Respond to order:created events.
     *
     * @param array $data
     */
    public function respondOrderCreated($data)
    {
        $this->log("Order # {$data['unique_id']} was created.");
    }
}
```

## Third-party Webhooks

You may receive webhooks from third-party sources as well by defining the necessary methods which verify and handle
the webhook request and payload.

The url to use when registering webhooks with a third-party can be generated using the `getWebhookUrl` method. An
optional `$method` parameter allows you to ensure the url ends with a specific string or to ensure it is unique.

```php
$url = $this->getWebhookUrl('someTopic');
```

Or via the CLI:

```
$ bin/mwrun ShipStream_Test getWebhookUrl
```

The following example demonstrates how to handle Shopify webhooks using HMAC to verify the authenticity for security:

```php
class ShipStream_Test_Plugin extends Plugin_Abstract
{
    /**
     * Return FALSE if the webhook cannot be verified to prevent further execution
     * 
     * @param array  $query
     * @param array  $headers
     * @param string $data
     * @return bool
     */
    public function verifyWebhook($query, $headers, $data)
    {
        $calculated_hmac = base64_encode(hash_hmac('sha256', $data, $this->getConfig('oauth_api_secret'), true));
        $hmac_header = $headers['X_SHOPIFY_HMAC_SHA256'];
        return strcmp($hmac_header, $calculated_hmac) === 0;
    }

    /**
     * Return TRUE if the webhook was handled successfully.
     * 
     * @param array  $query
     * @param array  $headers
     * @param string $data
     * @return bool
     * @throws Plugin_Exception
     */
    public function handleWebhook($query, $headers, $data)
    {
        $data = @json_decode($data, TRUE);
        if (NULL === $data || json_last_error() != JSON_ERROR_NONE) {
            throw new Plugin_Exception('An error occurred while decoding JSON encoded string.');
        }

        switch (str_replace('.', '/', $query['topic']))
        {
            case 'fulfillments/create':
                $this->fulfillmentCreateWebhook($data);
                return TRUE;
        }
        return FALSE;
    }

    /**
     * Fulfillment create webhook callback
     *
     * @param string $data
     * @return void
     * @throws Plugin_Exception
     */
    public function fulfillmentCreateWebhook($fulfillmentData)
    {
        if (empty($fulfillmentData['order_id'])) {
            throw new Plugin_Exception('Unknown order id.');
        }
        $orderId       = $fulfillmentData['order_id'];
        $fulfillmentId = $fulfillmentData['id'];
        $orderName     = preg_replace('/\.\d$/', '', $fulfillmentData['name']);

        if ($fulfillmentData['status'] != 'pending') {
            $this->log(sprintf('Skipping import of fulfillment %s with status %s', $fulfillmentId, $fulfillmentData['status']));
            return;
        }

        $this->addEvent('importFulfillmentEvent', ['order_id' => $orderId, 'fulfillment_id' => $fulfillmentId, 'order_name' => $orderName]);
        $this->log(sprintf('Webhook queued import for order %s, fulfillment %s', $orderId, $fulfillmentId));
    }
}
```

It is important to note that many webhook systems have retries and expect to receive a success response within a short
amount of time so it is advised to use the job queue to perform any potentially long-running work in the background.

## Third-party Remote Callbacks

Similarly to webhooks you can register specific callback methods which can be executed remotely. The primary difference
between webhooks and remote callbacks is that the latter maps one url to a specific method and the method can return
a payload in the HTTP response (vs just a 200 status).

Generate the url for the callback using `getCallbackUrl` and map this public method name to the
plugin's PHP method using the `plugin.xml` file.

```php
$url = $this->getCallbackUrl('testCallback');
```

```xml
<?xml version="1.0" encoding="utf-8"?>
<plugin>
    <ShipStream_Test>
        <routes>
            <testCallback>myCallbackMethod</testCallback>
        </routes>
    </ShipStream_Test>
</plugin>
```

```php
class ShipStream_Test_Plugin extends Plugin_Abstract
{
    public function myCallbackMethod($query, $headers, $data)
    {
        $rawData = $data;
        try {
            $data = json_decode($data, TRUE);
            if (NULL === $data || json_last_error() != JSON_ERROR_NONE) {
                throw new Plugin_Exception('An error occurred while decoding JSON encoded string.');
            }

            // Perform data validation
            if ( ! isset($data['payload'])) {
                throw new Plugin_Exception('Invalid data format.');
            }

            // Do something...
            
            $this->resolveError($rawData);
            return json_encode(['success' => TRUE]);
        } catch (Plugin_Exception $e) {
            $this->log($e->getMessage(), self::ERR, 'myCallbackMethod.log');
        } catch (Exception $e) {
            $this->log(get_class($e).': '.$e->getMessage(), self::ERR, 'myCallbackMethod.log');
            $this->logException($e);
        }
        $this->reportError($e, $rawData, TRUE, 'My Callback');
        throw $e;
    }
}
```

## Error Handling and Reporting

On the production environment errors may be reported to the user allowing the user to view the error information
in the user interface. If an error is automatically resolved due to an issue being fixed externally or an automatic
retry, your plugin should mark it as resolved. Errors are identified using a hash of the raw data passed when the
error is recorded so to mark an error resolved you must pass the same raw data that was originally passed when the
error was reported.

```php
$rawData = $orderData;
try {
    $this->call('order.create', $params);
    $this->resolveError($rawData);
} catch (Plugin_Exception $e) {
    $this->reportError($e, $rawData, TRUE, 'Create Order');
}
```

On the middleware environment the `reportError` and `resolveError` methods will have no effect other than to log the
error to `logs/errors.log`.

## Job Queue

For any actions that may take a significant amount of time or need to be retried later you should not run them
directly but rather run them using the job queue. For example if an action discovers 100 new orders to be created,
do not create them all serially in the foreground but rather create a separate job for each order so that the
errors can be reported and handled for each order individually. Jobs added to the queue which resulted in an error
can be retried by the user via the user interface.

```php
$this->addEvent('importOrderEvent', [
    'order_id' => $data['id'],
    'order_uuid' => $data['uuid'],
    'order_name' => $data['order_name'],
]);
$this->log(sprintf('Queued import for order %s (%s)', $data['id'], $data['uuid']), self::DEBUG);
```

The first parameter is the method name which will be called and the second parameter is an array that will be 
converted to a `Varien_Object` instance and passed to the method as the first parameter. Throw an exception to
indicate a failure which should be reported to the user and that can be retried by the user.

```php
    /**
     * Import a new order
     *
     * @param Varien_Object $data
     * @throws Plugin_Exception
     */
    public function importOrderEvent(Varien_Object $data)
    {
        if ( ! $data->hasData('id')) {
            throw new Plugin_Exception('The order data is invalid.');
        }
        // import the order...
    }
```

## Global Locking

*TODO*

## Logging

*TODO*

## Caching

*TODO*

## OAuth

*TODO*

## Composer Dependencies

Composer is currently not supported but we're considering it so let us know if you have a need for it.