#!/bin/bash
echo "docker-compose up executed"
cd "$(dirname "$(readlink -f "${0}")")/../"
echo $PWD
cd docker/
docker-compose up -d
