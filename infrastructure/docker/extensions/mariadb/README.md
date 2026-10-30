# MariaDB

The `mariadb` service, with a database `app` for the user `app` (password: `app`),
and the `mysql` PHP extension (PDO MySQL) in the images.

In the application:

```
DATABASE_URL="mysql://app:app@mariadb:3306/app?serverVersion=11.8.2-MariaDB&charset=utf8mb4"
```

The user can also create the databases prefixed with `app_`, e.g. `app_test` for the
tests: the volume gets this grant when MariaDB creates it, on its first start. On an
older volume, run it once:

```
castor mariadb -- --user=root --password= -e "GRANT ALL PRIVILEGES ON \`app\_%\`.* TO 'app'@'%';"
```

To query the database:

```
castor mariadb                          # interactive client
castor mariadb -- "SELECT now();"       # one-shot query
```

The version of MariaDB is in `docker-compose.version.yml`, which belongs to the
project: `castor starter:sync` never changes it.

To replace PostgreSQL with MariaDB: `castor starter:remove postgres`, then `castor
starter:add mariadb`.
