Middleware
========
Middleware is an abstracted and lightweight version of ShipStream's production environment. It can be used for
developing plugins for the production environment, or as a standalone "middle-man" between your systems and ours.


Features
--------
ShipStream plugins support the following features which work exactly the same in both the middleware environment (this repository)
and the production environment (closed-source).

* Run methods periodically via cron jobs (config.xml or your own linux cron jobs).
* Respond to ShipStream events in real time (via Webhooks if using the middleware environment).
* Respond to third-party Webhook events.

A plugin that is installed on the production environment can be configured by the user via the Merchant Panel or run in
the middleware environment with a relatively simple installation on a server running PHP. Either way, the functionality
of the plugin should be identical. While the "middleware" is intended to be mainly a development environment for testing,
you can just as well use it as the basis for your integration.


Requirements
--------

* The supported platform for developing Middleware plugins is PHP 5.4+ on Mac or Linux.
* A web server is required for testing if your plugin receives third-party webhooks or responds to events from ShipStream via
ShipStream's webhooks. Otherwise the plugins are only run from the command line or a crontab.
* [modman](https://github.com/colinmollenhour/modman) is used to deploy plugins.


Installation
--------
1. Clone this repository to a new directory and copy the sample config file.<br/>

    `$ git clone https://github.com/shipstream/middleware.git`<br/>
    `$ cd middleware`<br/>
    `$ cp app/etc/local.sample.xml app/etc/local.xml`

2. Edit app/etc/local.xml file and add your configuration.<br/>
Example:<br/>

    `<base_url>http://[WEBSITE BASE URL]/api/jsonrpc</base_url>`<br/>
    `<login>[API LOGIN]</login>`<br/>
    `<password>[API PASSWORD]</password>`<br/>

3. Clone the `Test_Test` plugin and run the `update_ip` method to confirm successful setup:<br/>

    `$ modman init`<br/>
    `$ modman clone https://github.com/shipstream/plugin-test.git`<br/>
    `$ php run.php Test_Test update_ip`

#### Windows Users

Although Windows is not supported, it should be possible to develop in Windows by using a [PHP port of modman](https://github.com/sitewards/modman-php)
or by manually creating the symlinks for a module. However, if you want your plugin to be installed on the production environment
it must operate perfectly under a Linux environment.


Create Your Own Plugin
--------
The easiest way to start your own plugin is to fork the [`Test_Test`](https://github.com/shipstream/plugin-test)
plugin and rename it. The minimal required file structure from the root of the middleware directory is as follows:<br/>

* app/code/community/{COMPANY_NAME}/{MODULE_NAME}
  * etc
    * config.xml
    * plugin.xml
  * Plugin.php
* app/etc/modules
  * {COMPANY_NAME}_{MODULE_NAME}.xml

As such, the `modman` file for the `Test_Test` plugin looks like this:

```
code             app/code/community/Test/Test/
Test_Test.xml    app/etc/modules/
```

Running Plugins
--------

Plugins can be run by executing the following command in the command line:<br/>

`$ php run.php {COMPANY_NAME}_{MODULE_NAME} {PLUGIN_METHOD}`
