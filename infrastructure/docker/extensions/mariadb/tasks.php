<?php

// The tasks of the mariadb extension (see README.md), imported by .castor/starter/index.php
// and updated by "castor starter:sync".

namespace mariadb;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\io;
use function docker\docker_compose;
use function docker\docker_compose_exec;

/**
 * Same idea as "castor postgres": proxies anything after "--" to the mariadb client
 * inside the mariadb container, in a non-interactive way with the exit code propagated.
 *
 * castor mariadb                          # interactive client
 * castor mariadb -- "SELECT now();"       # one-shot query
 * castor mariadb -- -e "SHOW TABLES"      # raw client arguments
 *
 * @param list<string> $params
 */
#[AsTask(description: 'Connect to the MariaDB database', aliases: ['mariadb'])]
function client(#[AsArgsAfterOptionEnd] array $params = []): int
{
    // The user and the database of the container, which a project may change
    $command = ['sh', '-c', 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" "$@"', 'mariadb'];

    if (!$params) {
        io()->title('Connecting to the MariaDB database');

        docker_compose(['exec', 'mariadb', ...$command], context()->toInteractive());

        return 0;
    }

    // Rebuild the query as a single argument, as the shell splits it into multiple words
    if (!str_starts_with((string) reset($params), '-')) {
        $params = ['-e', implode(' ', $params)];
    }

    return (int) docker_compose_exec([...$command, ...$params], context()->withInput(STDIN)->withAllowFailure(), service: 'mariadb')->getExitCode();
}
