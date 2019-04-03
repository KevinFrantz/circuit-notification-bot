#!/bin/bash
docker build -t circuit .
docker run --name circuit --rm -i -t circuit bash
