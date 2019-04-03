#!/usr/bin/env bash

cd "$(dirname "$(readlink -f "${0}")")/../"

# Download generated Swagger PHP client
# As of 12 Sep 2017 generator certificate chain is incomplete so we're using an static one-

CURL="curl --cacert .chain.pem" # --cacert chain.pem is the workaround incomplete certificate chain

conf="circuit-client-gen-conf.json"
lang="php"

packName=$(jq -r .packagePath $conf)

if [ -d "${packName}" ]; then
    echo -e >&2 "Client already exists, skipping download.\nRemove \"${packName}\" to force download."
    exit 2
fi

ziptmp=$(mktemp)

$CURL $(\
    $CURL -X POST -H "content-type:application/json" \
    -d $( jq -c '{ "swaggerUrl": "https://eu.yourcircuit.com/rest/v2/swagger/", "options": . }' $conf) \
    https://generator.swagger.io/api/gen/clients/${lang} | jq .link -r \
    ) > $ziptmp

unzip $ziptmp
mv php-client/* lib

rm -rf php-client $ziptmp
