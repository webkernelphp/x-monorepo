<?php declare(strict_types=1);
return [
    /*
    |--------------------------------------------------------------------------
    | Tag
    |--------------------------------------------------------------------------
    | The version tag to apply when splitting and tagging sub-repositories.
    | Can be overridden at runtime via the --tag CLI option.
    */
    'tag' => '',

    /*
    |--------------------------------------------------------------------------
    | Git identity defaults
    |--------------------------------------------------------------------------
    | Used when committing the "pre-tag" commit if no identity is configured
    | in the repository's .git/config.
    */
    'git_name'  => 'Webkernel Release Bot',
    'git_email' => 'releases@webkernel.io',

    /*
    |--------------------------------------------------------------------------
    | Monorepo root
    |--------------------------------------------------------------------------
    | Absolute path to the root of the monorepo (where .git lives).
    */
    'monorepo_root' => webapp_path(),

    /*
    |--------------------------------------------------------------------------
    | Packages directory
    |--------------------------------------------------------------------------
    | Path, relative to monorepo_root, where sub-packages live.
    | Each sub-package must have a composer.json with extra.webkernel.package_repo.
    */
    'packages_dir' => 'packages',

    /*
    |--------------------------------------------------------------------------
    | State file
    |--------------------------------------------------------------------------
    | Path (absolute or relative to monorepo_root) of the JSON file used to
    | store resumable split operation state.
    */
    'state_file' => 'storage/x-monorepo-state.json',

    /*
    |--------------------------------------------------------------------------
    | Process timeout
    |--------------------------------------------------------------------------
    | Maximum seconds a single git sub-process may run before being killed.
    */
    'process_timeout' => 600,

    /*
    |--------------------------------------------------------------------------
    | Push URL mode
    |--------------------------------------------------------------------------
    | Controls how GitHub SSH package_repo URLs are pushed.
    | - auto: match the monorepo origin protocol, falling back to SSH
    | - https: always convert git@github.com:owner/repo.git to HTTPS
    | - ssh: use the package_repo URL unchanged
    */
    'push_url_mode' => 'auto',

    /*
    |--------------------------------------------------------------------------
    | Default branch
    |--------------------------------------------------------------------------
    */
    'default_branch' => 'main',

    /*
    |--------------------------------------------------------------------------
    | Changelog
    |--------------------------------------------------------------------------
    */
    'changelog' => [
        'enabled'   => false,
        'filename'  => 'CHANGELOG.md',
        'header'    => '# Changelog',
        'date_format' => 'Y-m-d',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed split prefixes
    |--------------------------------------------------------------------------
    | Only packages whose directory path starts with one of these prefixes
    | (relative to packages_dir) will be included in split operations.
    | Empty array = allow all.
    */
    'allowed_prefixes' => [],

    /*
    |--------------------------------------------------------------------------
    | Excluded packages
    |--------------------------------------------------------------------------
    | Exact package names (as declared in composer.json "name") to skip.
    */
    'excluded_packages' => [],
];
