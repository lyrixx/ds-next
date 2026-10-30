<?php

// The tasks of the clickhouse extension (see README.md), imported by
// .castor/starter/index.php and updated by "castor starter:sync".

namespace clickhouse;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\io;
use function docker\docker_compose;
use function docker\docker_compose_exec;

/**
 * Same idea as "castor postgres": proxies anything after "--" to clickhouse-client
 * inside the clickhouse container, in a non-interactive way with the exit code propagated.
 *
 * castor clickhouse                               # interactive client
 * castor clickhouse -- "SELECT version();"        # one-shot query
 * castor clickhouse -- --query "SHOW TABLES"      # raw client arguments
 *
 * @param list<string> $params
 */
#[AsTask(description: 'Connect to the ClickHouse database', aliases: ['clickhouse'])]
function client(#[AsArgsAfterOptionEnd] array $params = []): int
{
    // The user and the database of the container, which a project may change
    $command = ['sh', '-c', 'exec clickhouse-client --user "$CLICKHOUSE_USER" --password "$CLICKHOUSE_PASSWORD" --database "$CLICKHOUSE_DB" "$@"', 'clickhouse-client'];

    if (!$params) {
        io()->title('Connecting to the ClickHouse database');

        docker_compose(['exec', 'clickhouse', ...$command], context()->toInteractive());

        return 0;
    }

    // Rebuild the query as a single argument, as the shell splits it into multiple words
    if (!str_starts_with((string) reset($params), '-')) {
        $params = ['--query', implode(' ', $params)];
    }

    return (int) docker_compose_exec([...$command, ...$params], context()->withInput(STDIN)->withAllowFailure(), service: 'clickhouse')->getExitCode();
}
