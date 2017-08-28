#!/bin/bash
CONTAINER_NAME="phpapi"
docker stop "$CONTAINER_NAME"
docker rm "$CONTAINER_NAME"
docker run -dit -p 3333:3306 -p 8888:80 --name="$CONTAINER_NAME" -v "$(pwd)"/public:/var/www/html -v "$(pwd)"/secure:/var/www/secure phpapi
docker exec -it "$CONTAINER_NAME" mkdir /var/www/secure/lib/keys
docker exec -it "$CONTAINER_NAME" chown 33:33 /var/www/secure/lib/keys
docker logs -f "$CONTAINER_NAME"