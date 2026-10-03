<?php

// Shared by docker-starter with its projects: change it in docker-starter, never in a project (see below).

// Entry point of the castor tasks shared by docker-starter with the projects derived
// from it. A project can either keep a copy of this directory (imported with the rest
// of .castor/), or install it as a Castor remote package, to update it with
// `castor composer update` (see "How to update docker-starter in a project" in the
// README of docker-starter, which also explains how to update the other files with
// `castor starter:sync`):
//
//   import('composer://jolicode/docker-starter', file: '.castor/starter/index.php');
//
// In a project, this directory is read-only, like a vendor directory: `castor
// starter:sync` or `castor composer update` updates it. Configure these tasks with the
// variables of create_default_variables() in castor.php, add your own tasks in
// castor.php or in the other files of .castor/, and change what a shared task does with
// a Castor listener (see "Change a shared task with a listener" in the README of
// docker-starter).

use function Castor\import;

import(__DIR__ . '/context.php');
import(__DIR__ . '/docker.php');
import(__DIR__ . '/postgres.php');
import(__DIR__ . '/qa.php');
import(__DIR__ . '/sync.php');
import(__DIR__ . '/worktree.php');
