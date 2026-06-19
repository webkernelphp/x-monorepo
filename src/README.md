# Webkernel XMonorepo

Monorepo split orchestration built on top of [webkernel/standard-git](../standard-git).

> [!NOTE]
> In Webkernel, the `vendor/` directory is named `third_party/` as a matter of legal precision. While "vendor" implies a commercial supplier, "third-party" accurately describes any external code governed by its own open-source license. Since this external code isn't ours, it requires strict tracking for compliance and security—making it a distinct legal responsibility from our own source code.

## What it does

- **Discovers** sub-packages by scanning for `composer.json` files that declare `extra.webkernel.package_repo`.
- **Splits** each package's history into its own repository using `git subtree split`.
- **Pushes** the split history and version tags to each package's split repository.
- **Tracks** progress in a JSON state file so interrupted runs can be resumed.
- **Writes** changelog entries per package.

## Installation

```bash
composer require webkernel/x-monorepo
```

## Package eligibility

A package is eligible for splitting when its `composer.json` declares:

```json
{
    "name": "vendor/my-package",
    "extra": {
        "webkernel": {
            "package_repo": "git@github.com:third_party/my-package.git",
            "branch": "main"
        }
    }
}
```

## Fluent API

```php
$xMonorepo = (new \Webkernel\XMonorepo\XMonorepo(new \Webkernel\StdGit\StdGit($runner)))
    ->dotGitRoot(webapp_path())
    ->connect(username: $username, repo: $repo)
    ->ensureIfCommitted(
        ifNotCommit:      "Pre Tag $tag commit",
        tag:              $tag,
        splitFrom:        $packagesRootDir,
        splitStateFile:   $splitStateFileDest,
        makeSplitReposRo: true,
    );
```

## CLI

```bash
# List all eligible packages
third_party/bin/x-monorepo discover

# Split all packages at a given tag
third_party/bin/x-monorepo split --tag=1.2.3

# Split a single package
third_party/bin/x-monorepo split --tag=1.2.3 --package=third_party/my-package

# Check split status
third_party/bin/x-monorepo status --tag=1.2.3
```

## Configuration

Copy `config/x-monorepo.php` into your project's config directory and adjust values.
Key options:

| Key                 | Description                                                  |
| ------------------- | ------------------------------------------------------------ |
| `packages_dir`      | Path relative to monorepo root where packages live.          |
| `state_file`        | JSON file path for resumable operation state.                |
| `default_branch`    | Branch to push to in split repositories.                     |
| `push_safety_url`   | Dummy push URL applied to read-only split remotes.           |
| `excluded_packages` | Composer package names to skip.                              |
| `allowed_prefixes`  | Limit splits to packages under these sub-directory prefixes. |

## Architecture

```
XMonorepo (facade + fluent chain)
  ├── Config/ConfigLoader
  ├── Engine/Discovery/PackageDiscovery  -- finds eligible packages
  ├── Engine/SplitEngine                -- runs git subtree split + push
  ├── Engine/ChangelogWriter            -- prepends changelog entries
  ├── Engine/State/StateManager         -- wraps StdGit IOperationStateStore
  └── Commands/{Discover,Split,Status}Command
```

All git operations go through `webkernel/standard-git`.
