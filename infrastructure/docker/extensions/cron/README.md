# Cron

A `cron` service in dev, which runs the jobs of the `crontab` file of this directory,
from the PHP image of the stack (the `cron` stage of
`infrastructure/docker/services/php/Dockerfile`).

The `crontab` belongs to the project: `castor starter:sync` never changes it. It is
read when the container starts: restart it after a change, with `castor docker:stop
--service=cron && castor docker:up --service=cron`.

The jobs run as your user, so that the files they write (cache, logs...) belong to
you. To see their output with `castor logs --service=cron`, redirect it to
`/tmp/cron.log`:

```crontab
0 * * * * cd /var/www/application && bin/console app:my-command > /tmp/cron.log 2>&1
```

The `cron` service only exists in dev. In production, have the orchestrator run the
jobs with the `php` image (`bin/console ...`).
