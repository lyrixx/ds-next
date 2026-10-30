<?php

// Shared by docker-starter with its projects: change it in docker-starter, never in a project (see index.php).

namespace context;

use Castor\Attribute\AsContext;
use Castor\Context;
use Castor\Helper\PathHelper;
use Symfony\Component\Process\Process;

use function Castor\log;
use function worktree\get_worktree_name;

use const starter\EXTENSIONS_DIR;

#[AsContext(default: true)]
function create_default_context(): Context
{
    $data = create_default_variables() + [
        'project_name' => 'app',
        'root_domain' => 'app.test',
        'extra_domains' => [],
        'php_version' => '8.5',
        // How the images run PHP: 'fpm' (nginx and php-fpm) or 'frankenphp'
        'php_server' => 'fpm',
        // Directory of the application, relative to the repository root ('' when the
        // application lives at the root of the repository)
        'app_dir' => 'application',
        // Port of the nginx container, published when there is no router (prod context).
        // The HTTP_PORT environment variable takes precedence.
        'http_port' => 8000,
        'registry' => null,
        // Extensions installed in every image on top of those of docker-starter and of its
        // extensions, by their name in the Debian packages: e.g. 'imagick' for php8.5-imagick
        'php_extensions' => [],
        // Debian packages installed in every image (e.g. 'imagemagick'), and only in the
        // builder (e.g. 'mariadb-client')
        'system_packages' => [],
        'builder_system_packages' => [],
        // Node.js and Yarn in the builder: true for the version of the Dockerfile, a version
        // (e.g. '22' or '22.x'), or false for none (e.g. with the Symfony AssetMapper)
        'nodejs' => true,
        // Directories of the application written at runtime in production, usually the
        // mount points of volumes: owned by the application user in the "php" image
        'writable_dirs' => [],
        'docker_compose_files' => [
            'docker-compose.yml',
            'docker-compose.dev.yml',
        ],
        'docker_compose_run_environment' => [],
        // Service in which `docker_compose_run()` executes commands (composer, bin/console...)
        'docker_compose_run_service' => 'builder',
        'macos' => false,
        'power_shell' => false,
        // check if posix_geteuid is available, if not, use getmyuid (windows)
        'user_id' => \function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(),
        'root_dir' => PathHelper::getRoot(),
    ];

    // The extensions of the project (see the README of the demo extension), whatever the
    // variables of castor.php say: they add their compose files, their PHP extensions,
    // and their reverse proxy in front of the frontend
    $data['extensions'] = get_extensions($data['root_dir']);
    $data['reverse_proxies'] = [];
    foreach ($data['extensions'] as $extension) {
        $data['docker_compose_files'] = [
            ...$data['docker_compose_files'],
            ...get_existing_docker_compose_files($data['root_dir'], [
                "extensions/{$extension}/docker-compose.yml",
                "extensions/{$extension}/docker-compose.version.yml",
            ]),
        ];
        $manifest = read_extension_manifest($data['root_dir'], $extension);
        $data['php_extensions'] = [...$data['php_extensions'], ...$manifest['php_extensions']];
        if ($manifest['reverse_proxy']) {
            $data['reverse_proxies'][] = $manifest['reverse_proxy'];
        }
    }
    $data['php_extensions'] = array_values(array_unique($data['php_extensions']));

    $data['docker_compose_files'] = [
        ...$data['docker_compose_files'],
        ...get_existing_docker_compose_files($data['root_dir'], ['docker-compose.project.yml', 'docker-compose.override.yml']),
    ];

    $data['app_dir'] = trim($data['app_dir'], '/');

    if (!\in_array($data['php_server'], ['fpm', 'frankenphp'], true)) {
        throw new \RuntimeException(\sprintf('The "php_server" variable of castor.php must be "fpm" or "frankenphp", not "%s".', $data['php_server']));
    }

    $platform = strtolower(php_uname('s'));
    if (str_contains($platform, 'darwin')) {
        $data['macos'] = true;
    } elseif (\in_array($platform, ['win32', 'win64', 'windows nt'], true)) {
        $data['power_shell'] = true;
    }

    //                                                   2³² - 1
    if (false === $data['user_id'] || $data['user_id'] > 4294967295) {
        $data['user_id'] = 1000;
    }

    if (0 === $data['user_id']) {
        log('Running as root? Fallback to fake user id.', 'warning');
        $data['user_id'] = 1000;
    }

    $worktreeName = get_worktree_name($data['root_dir']);
    if (null !== $worktreeName) {
        $data['project_name'] .= '-wt-' . $worktreeName;
    }

    // Only when someone can answer the prompts of the commands: stdin is a terminal, outside of
    // a CI (as for Castor). Otherwise (CI, pipes, AI agents...), a PTY would give them a terminal
    // nobody types in, and a prompt (composer, bin/console...) would wait forever. Unlike
    // Context::supportsInteraction(), stdout may be piped (e.g. `castor init | tee init.log`).
    $canAnswerPrompts = false === getenv('CI') && stream_isatty(STDIN);

    return new Context(
        $data,
        pty: Process::isPtySupported() && $canAnswerPrompts,
        environment: [
            'BUILDKIT_PROGRESS' => 'plain',
        ]
    );
}

/**
 * Production images (see the "Production stages" of the Dockerfile): the same tasks as in
 * dev, on a dedicated compose stack. E.g. `castor start -c prod`, `castor docker:push -c prod`.
 */
#[AsContext(name: 'prod')]
function create_prod_context(): Context
{
    $c = create_default_context();

    $files = ['docker-compose.prod.yml'];
    // A single "php" image, without nginx
    if ('frankenphp' === $c['php_server']) {
        $files[] = 'docker-compose.prod.frankenphp.yml';
    }
    foreach ($c['extensions'] as $extension) {
        // Only the extensions with services in this stack: e.g. no mail catcher
        if (get_existing_docker_compose_files($c['root_dir'], ["extensions/{$extension}/docker-compose.prod.yml"])) {
            $files = [
                ...$files,
                ...get_existing_docker_compose_files($c['root_dir'], [
                    "extensions/{$extension}/docker-compose.prod.yml",
                    "extensions/{$extension}/docker-compose.version.yml",
                ]),
            ];
        }
    }
    $files = [
        ...$files,
        ...get_existing_docker_compose_files($c['root_dir'], ['docker-compose.prod.project.yml', 'docker-compose.prod.override.yml']),
    ];

    return $c->withData(
        [
            // Dedicated compose project: never collides with the dev stack (containers, volumes)
            'project_name' => $c['project_name'] . '-prod',
            'docker_compose_files' => $files,
            // There is no builder service in this stack: commands run in the application image
            'docker_compose_run_service' => 'php',
        ],
        recursive: false,
    );
}

#[AsContext(name: 'test')]
function create_test_context(): Context
{
    $c = create_default_context();

    return $c
        ->withEnvironment([
            'APP_ENV' => 'test',
        ])
    ;
}

#[AsContext(name: 'ci')]
function create_ci_context(): Context
{
    $c = create_test_context();

    return $c
        ->withEnvironment([
            'COMPOSE_ANSI' => 'never',
        ])
    ;
}

/**
 * The optional compose files of a stack, among $files (relative to infrastructure/docker/),
 * e.g. those of the project (*.project.yml, committed, never touched by "castor
 * starter:sync"), then the local tweaks of a developer (*.override.yml, gitignored, e.g. to
 * mount a secret).
 *
 * @param list<string> $files
 *
 * @return list<string>
 */
function get_existing_docker_compose_files(string $rootDir, array $files): array
{
    return array_values(array_filter($files, static fn (string $file) => file_exists("{$rootDir}/infrastructure/docker/{$file}")));
}

/**
 * The extensions of the project: the directories of infrastructure/docker/extensions/.
 *
 * @return list<string>
 */
function get_extensions(string $rootDir): array
{
    $dirs = glob("{$rootDir}/" . EXTENSIONS_DIR . '/*', GLOB_ONLYDIR) ?: [];

    return array_map(basename(...), $dirs);
}

/**
 * What an extension adds to the stack, from its optional extension.json: PHP extensions,
 * and the service of the extension which is a reverse proxy, with its port.
 *
 * @return array{php_extensions: list<string>, reverse_proxy: array{service: string, port: int}|null}
 */
function read_extension_manifest(string $rootDir, string $extension): array
{
    $manifest = ['php_extensions' => [], 'reverse_proxy' => null];
    $file = "{$rootDir}/" . EXTENSIONS_DIR . "/{$extension}/extension.json";

    if (!is_file($file)) {
        return $manifest;
    }

    $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

    // An extension written for a newer docker-starter: its key would be silently ignored
    if ($unknown = array_diff(array_keys($data), array_keys($manifest))) {
        throw new \RuntimeException(\sprintf('The "%s" file has unknown keys (%s): update docker-starter with "castor starter:sync", or fix the file.', EXTENSIONS_DIR . "/{$extension}/extension.json", implode(', ', $unknown)));
    }

    $manifest = $data + $manifest;
    if (!array_is_list($manifest['php_extensions']) || array_filter($manifest['php_extensions'], static fn ($name) => !\is_string($name))) {
        throw new \RuntimeException(\sprintf('The "php_extensions" of the "%s" file must be a list of names, e.g. ["redis"].', EXTENSIONS_DIR . "/{$extension}/extension.json"));
    }

    $proxy = $manifest['reverse_proxy'];
    if (null !== $proxy && (!\is_array($proxy) || !\is_string($proxy['service'] ?? null) || !\is_int($proxy['port'] ?? null) || \count($proxy) > 2)) {
        throw new \RuntimeException(\sprintf('The "reverse_proxy" of the "%s" file must be a service and its port, e.g. {"service": "varnish", "port": 80}.', EXTENSIONS_DIR . "/{$extension}/extension.json"));
    }

    return [
        'php_extensions' => $manifest['php_extensions'],
        'reverse_proxy' => null === $proxy ? null : ['service' => $proxy['service'], 'port' => $proxy['port']],
    ];
}
