# composer-check-updates

[npm-check-updates](https://github.com/raineorshine/npm-check-updates) for Composer. `ccu` lists the root
requirements whose newest release is beyond what `composer.json` allows, rewrites their constraints in the style you
already use, and runs `composer update` for them. `ccu -i` lets you pick the upgrades, and the version of each, in a
terminal UI built on [symfony/tui](https://symfony.com/doc/current/tui.html).

```
$ ccu
Checking /path/to/project/composer.json

 doctrine/orm               ^3.5.2   →  ^3.7.6
 symfony/console            ^7.4.1   →  ^8.1.11
 phpunit/phpunit (dev)      ^12.4.1  →  ^13.4.6

Held back
 phpunit/phpunit 13.4.7 was released 2026-11-13 and is in the cooldown for another 4 days 12 hours

Run ccu -u to upgrade composer.json
```

It looks versions up with Composer's own `VersionSelector`, so it sees what `composer update` would: your custom
repositories, `minimum-stability` and stability flags, your platform (PHP version and extensions, including
`config.platform`) and your [cooldown policy](https://getcomposer.org/doc/06-config.md#policy). A release that's still
in the cooldown, or that needs a newer PHP, is listed under "Held back" instead of offered.

## Why not …

- **`composer outdated`** lists newer releases but doesn't change `composer.json`.
- **`composer bump`** raises constraints to the *installed* versions, so it never crosses a major version.
- **`composer require vendor/package -W`** does one package at a time, and writes `^X.Y` whatever style you used.
- **[rector/swiss-knife](https://github.com/rectorphp/swiss-knife)'s `open-versions`** (formerly rector/jack) widens
  constraints to the *next* version, for upgrading step by step.
- **[webworkerjoshua/composer-check-updates](https://github.com/webworkerJoshua/composer-check-updates)** and
  **[hpbxxtr/composer-upgrade-interactive](https://github.com/hpbxxtr/composer-upgrade-interactive)** are similar tools
  that run as Composer plugins. Neither applies Composer's cooldown policy, so with one configured they offer
  releases that `composer update` then refuses. A plugin also runs inside `composer.phar`, which bundles Symfony 5.4,
  so it can't use symfony/tui.

## Installation

```bash
composer require --dev jack-worman/composer-check-updates
```

or globally:

```bash
composer global require jack-worman/composer-check-updates
```

It needs PHP 8.4 or later. It's a standalone binary, not a Composer plugin, so there's nothing to allow in
`allow-plugins`.

## Usage

```bash
ccu                       # list the upgrades
ccu -u                    # write them to composer.json, then offer to run composer update
ccu -i                    # pick them in a terminal UI
ccu 'symfony/*'           # only check some packages
ccu -x 'phpunit/*'        # skip some packages
ccu --dep dev             # only check require-dev
ccu -t minor              # stay on each package's current major version
ccu --format group        # group the list by patch, minor and major
ccu --format json         # for scripts
ccu -e 2                  # exit with 1 when there are upgrades, for CI
```

### Constraint styles

Each constraint keeps its operator and precision:

| Before | Latest | After |
|---|---|---|
| `^1.2.3` | 3.4.5 | `^3.4.5` |
| `^1.2` | 3.4.5 | `^3.4` |
| `~1.2.3` | 3.4.5 | `~3.4.5` |
| `1.2.*` | 3.4.5 | `3.4.*` |
| `1.2.3` | 3.4.5 | `3.4.5` |
| `>=1.2` | 3.4.5 | `>=3.4` |
| `^1.2@beta` | 3.4.5 | `^3.4@beta` |
| `^1.2.3` | 1.4.0 | `^1.4.0` |
| `^6.4 \|\| ^7.0` | 8.1.2 | `^8.1` |
| `^6.4 \|\| ^7.0` | 8.1.2 | `^6.4 \|\| ^7.0 \|\| ^8.1` with `--widen` |

A constraint that already allows several majors with `||` is left alone until the latest release is outside all of
them. `--widen` keeps what any constraint allows and appends the new version, which suits libraries. Ranges such as
`>=1.0 <2.0` become `^X.Y.Z`. `*`, `dev-*` branches, `self.version` and platform packages (`php`, `ext-*`) are skipped.

### Interactive mode

```
Choose which packages to upgrade
↑↓ move · space select · ←→ version · a all · enter upgrade · esc cancel

Patch  Backwards-compatible bug fixes
❯ ◉ symfony/console        ^8.1.10  →  ^8.1.11      2026-11-04

Minor  Backwards-compatible features
  ◉ league/uri             ^7.9.1   →  ^7.10.0      2026-10-29

Major  Potentially breaking changes
  ◯ phpunit/phpunit (dev)  ^12.4.1  →  ^13.4.6  ‹   2026-11-03

Held back: phpunit/phpunit 13.4.7 was released 2026-11-13 and is in the cooldown for another 4 days 12 hours
2 of 3 selected · 1 newer release held back
```

Major upgrades start unselected. ← and → switch a row between the newest patch release, the newest release on the
current major version and the latest release.

### Options

| Option | |
|---|---|
| `[filter]...`, `--filter` | Only check these packages. `*` is a wildcard, and a value can list several, separated by commas. |
| `-x`, `--reject` | Skip these packages. |
| `-u`, `--upgrade` | Write the new constraints to `composer.json`. |
| `-i`, `--interactive` | Pick the upgrades in a terminal UI, then write them. |
| `-t`, `--target` | `latest` (default), `minor` (newest on the current major), `patch` (newest on the current minor) or `semver` (newest the current constraint allows, like `composer update` then `composer bump`). |
| `--dep` | `prod`, `dev` or `prod,dev` (default). |
| `--format` | `text` (default), `group` or `json`. |
| `--widen` | Append the new version to a constraint instead of replacing it. |
| `-e`, `--error-level` | `1` (default) exits with 0. `2` exits with 1 when there are upgrades. |
| `--install` | After writing `composer.json`, run `composer update <packages> --with-all-dependencies`: `prompt` (default, only in a terminal), `always` or `never`. If the update fails, `composer.json` is restored. |
| `--composer` | The Composer binary to run. Defaults to the one running the script (`COMPOSER_BINARY`), then `composer` on the `PATH`. |
| `--cooldown-period` | Overrides `policy.cooldown.period`, such as `3 days`. `0` turns the cooldown off. |
| `--ignore-platform-reqs`, `--ignore-platform-req` | As for `composer update`. |
| `-d`, `--working-dir` | The directory with the `composer.json`. |

As a Composer script:

```json
"scripts": {
    "update-packages": "ccu -i"
}
```

## License

MIT
