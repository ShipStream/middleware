ShipStream Merchant API Middleware
========
The ShipStream Merchant API Middleware is an abstracted and lightweight version of ShipStream's production environment.
With it, you can develop and test plugins destined for the ShipStream WMS, or as a standalone "middle-man" between your
systems and ShipStream.


Features
--------
ShipStream plugins support the following features which work exactly the same in both the middleware environment (this repository)
and the production environment (ShipStream WMS).

* Interact with ShipStream's [Merchant API](https://docs.shipstream.io) either as an embedded code or over https 
* Run methods periodically via cron jobs
* Respond to ShipStream events in real time (via Webhooks if using the middleware environment)
* Respond to third-party Webhook events
* Queue tasks to be executed at a later time or in the background with automatic retries
* Use state management and caching for efficiency

A plugin that is installed on the production environment can be configured by the user via the Merchant Panel or run in
the middleware environment with a relatively simple installation using our Docker container. Either way, the functionality
of the plugin should be identical. While the "middleware" is intended to be mainly a development environment for testing,
you can just as well use it as the basis for your integration or as a standalone app on your own hosting environment.


Requirements
--------

* The supported platform for developing Middleware plugins is PHP 7.2+ on Mac or Linux.
* A web server is required for testing if your plugin receives third-party webhooks or responds to events from
  ShipStream via ShipStream's webhooks. Otherwise, the plugins are only run from the command line or a crontab.
* [modman](https://github.com/colinmollenhour/modman) is used to deploy plugins.


Installation
--------
1. Clone this repository to a new directory and copy the sample config file.<br/>

    `$ git clone https://github.com/ShipStream/middleware.git`<br/>
    `$ cd middleware`<br/>
    `$ cp app/etc/local.sample.xml app/etc/local.xml`

2. Edit app/etc/local.xml file and add your configuration.<br/>
Example:<br/>

    `<base_url>https://[WEBSITE BASE URL]/api/jsonrpc</base_url>`<br/>
    `<login>[API LOGIN]</login>`<br/>
    `<password>[API PASSWORD]</password>`<br/>

3. Clone the `ShipStream_Test` plugin and run the `update_ip` method to confirm a successful setup:<br/>

    `$ bin/modman init`<br/>
    `$ bin/modman clone https://github.com/ShipStream/plugin-test.git`<br/>
    `$ php run.php ShipStream_Test update_ip`

#### Windows Users

WSL2 is recommended for development in Windows and should be just like developing on Linux when using the Docker container. 

Create Your Own Plugin
--------
The easiest way to start your own plugin is to fork the [`ShipStream_Test`](https://github.com/ShipStream/plugin-test)
plugin and rename it. The minimal required file structure from the root of the middleware directory is as follows:<br/>

* app/code/community/{COMPANY_NAME}/{MODULE_NAME}
  * etc
    * config.xml
    * plugin.xml
  * Plugin.php
* app/etc/modules
  * {COMPANY_NAME}_{MODULE_NAME}.xml

As such, the `modman` file for the `ShipStream_Test` plugin looks like this:

```
code                   app/code/community/Test/Test/
ShipStream_Test.xml    app/etc/modules/
```

Running Plugins
--------

Plugins can be run by executing the following command in the command line:

```
$ docker-compose run plugin ShipStream_Test updateIp
```
