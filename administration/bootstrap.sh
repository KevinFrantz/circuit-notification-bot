#!/bin/bash
cd "$(dirname "$(readlink -f "${0}")")/"
echo "bootstrap starts..."
bash ./start-docker.sh
docker exec -it circuit bash ./administration/fetch-api-client.sh
docker exec -it circuit bash ./administration/composer-install.sh
bash ./docker-shell.sh
