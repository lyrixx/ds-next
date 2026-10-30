# Run by the entrypoint of the image when it creates the database (the first start of
# the volume): lets the user of the application create and use the databases prefixed
# with the name of its own, e.g. app_test for the tests (doctrine:database:create
# --env=test), as with PostgreSQL. Updated by "castor starter:sync".

docker_process_sql <<<"GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE//_/\\_}\\_%\`.* TO '${MARIADB_USER}'@'%';"
