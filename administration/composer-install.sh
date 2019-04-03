#!/bin/bash
echo "Installs from composer files  "
cd "$(dirname "$(readlink -f "${0}")")/../"
(cd lib/bot && composer install)
(cd lib/plugin-ex && composer install)
(cd lib/plugin-feed-poll && composer install)
