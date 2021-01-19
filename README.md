ShipStream Merchant API Middleware
========
The ShipStream Merchant API Middleware is an abstracted and lightweight version of ShipStream's production environment.
With it, you can develop and test plugins destined to become integrated ShipStream WMS plugins, or use it as a standalone
"middle-man" between your systems and ShipStream.


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
the middleware environment with a very simple installation using our Docker container. Either way, the functionality
of the plugin should be identical. While the "middleware" is intended to be mainly a development environment for testing,
you can just as well use it as the basis for your integration or as a standalone app on your own hosting environment.


Requirements
--------

* The supported platform for developing Middleware plugins is PHP 7.4 on Docker (container build files provided).
* A publicly accessible URL is required if your plugin receives third-party webhooks or responds to events from
  ShipStream via ShipStream's webhooks.

### Windows

Developing on Windows requires [WSL2 (Windows Subsystem for Linux)](https://docs.microsoft.com/en-us/windows/wsl/install-win10)
and [Docker Desktop for Windows](https://docs.docker.com/docker-for-windows/install/).

### Mac

Developing on Mac requires [Docker Desktop for Mac](https://docs.docker.com/docker-for-mac/install/).


Installation
--------

1. Clone this repository to a new directory:
   ```
   $ git clone https://github.com/shipstream/middleware.git
   $ cd middleware
   ```
   
2. Copy and edit the sample config file to add your configuration:
   ```
   $ cp app/etc/local.sample.xml app/etc/local.xml
   ```
   Example:
   ```
   <?xml version="1.0"?>
   <config>
     <default>
       <middleware>
         <system>
           <base_url>http://localhost/</base_url>
           <log>stdout</log>
         </system>
         <api>
           <base_url>https://[WEBSITE BASE URL]/api/jsonrpc</base_url>
           <login>[API LOGIN]</login>
           <password>[API PASSWORD]</password>
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
   ```


Create Your Own Plugin
--------

The easiest way to start your own plugin is to fork the [`ShipStream_Test`](https://github.com/shipstream/plugin-test)
project which you would have cloned in step 3 and then rename and edit it as needed.

The minimal required file structure from the root of the middleware directory is as follows:

* app/code/community/{COMPANY_NAME}/{MODULE_NAME}
  * etc
    * config.xml
    * plugin.xml
  * Plugin.php
* app/etc/modules
  * {COMPANY_NAME}_{MODULE_NAME}.xml

Modman is used to symlink the files into the project directories so the `modman` file for the
`ShipStream_Test` plugin looks like this:

```
code                   app/code/community/ShipStream/Test/
ShipStream_Test.xml    app/etc/modules/
```

After modifying the modman file be sure to run the `modman deploy` command to update symlinks:

```
$ bin/modman deploy-all
```

Handy Tips
--------

### Running plugin methods

Plugin methods can be run by executing the following command in the command line:

```
$ bin/mwrun ShipStream_Test updateIp
```

### Cron Tasks

If just developing, you do not need to schedule a crontab task, you can just run the cron method using `mwrun` as seen above.