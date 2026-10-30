#!/bin/bash
# Entrypoint of the dev "cron" container of the cron extension (see README.md): the cron
# daemon runs as root, the jobs as the host user, so that the files they write in the
# repository (cache, logs...) belong to them
set -euo pipefail

crontab="$(dirname "$0")/crontab"
if [ ! -f "${crontab}" ]; then
    echo "There is no crontab: create infrastructure/docker/extensions/cron/crontab." >&2
    exit 1
fi

getent group "${USER_ID}" >/dev/null || groupadd -g "${USER_ID}" app
getent passwd "${USER_ID}" >/dev/null || useradd -M -d /home/app -u "${USER_ID}" -g "${USER_ID}" -s /bin/bash app
crontab -u "$(getent passwd "${USER_ID}" | cut -d: -f1)" "${crontab}"

# The jobs cannot write to the output of the container (it belongs to root): they write
# to this FIFO instead, which is forwarded to it
fifo=/tmp/cron.log
rm -f "${fifo}"
mkfifo -m 0666 "${fifo}"
while true; do
    cat "${fifo}"
done &

exec "$@"
