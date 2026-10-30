<?php

// The tasks of the demo extension (see README.md), imported by .castor/starter/index.php
// and updated by "castor starter:sync".

namespace demo;

use Castor\Attribute\AsTask;

use function docker\docker_compose_run;

#[AsTask(description: 'Calls the demo service from the builder', namespace: 'demo')]
function call(): void
{
    docker_compose_run(['curl', '--fail', '--silent', 'http://demo']);
}
