#!/bin/sh
echo "PLUGIN=$PLUGIN" > /etc/environment
exec cron -f