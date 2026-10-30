# AGENTS.md

This file provides guidance to coding agents when working with code in this repository.

## Project Overview

This is a Composer plugin for Maho (a Magento 1.x fork). It provides three main plugins that integrate into Composer's event system to automate module installation, asset copying, and autoloading for Maho-based projects.

## Development Commands

### Static Analysis and Code Style
```bash
vendor/bin/phpstan analyse
vendor/bin/php-cs-fixer fix --diff --dry-run
vendor/bin/rector --dry-run
```

### Installing Dependencies
```bash
composer install
```

## Architecture

### Plugin System

The plugin exports three separate Composer plugins via `composer.json > extra > class`:
- `AutoloadPlugin` - Dynamic autoload configuration
- `ModmanPlugin` - Modman file parsing and symlink management
- `FileCopyPlugin` - Asset copying from modules

Each plugin implements `PluginInterface` and `EventSubscriberInterface` from Composer.

`AttributeCompiler` and `ApiPermissionCompiler` are not plugins. `AutoloadPlugin` runs them.

### Core Components

**AutoloadPlugin** (src/AutoloadPlugin.php)
- Hooks into the `PRE_AUTOLOAD_DUMP` and `POST_AUTOLOAD_DUMP` events
- Dynamically generates PSR-0 prefixes, classmaps, and file includes for Maho's non-standard structure
- Delegates actual path/classmap generation to `AutoloadRuntime`
- When `--optimize` flag is used, generates full classmap; otherwise uses PSR-0 prefixes
- On `POST_AUTOLOAD_DUMP`, registers a minimal autoloader for the Maho classes and runs `AttributeCompiler`, then `ApiPermissionCompiler`. It skips both when `Maho\Config\Observer` does not exist, and skips `ApiPermissionCompiler` when API Platform or `Maho\Config\ApiResource` does not exist

**AutoloadRuntime** (src/AutoloadRuntime.php)
- Standalone runtime class (doesn't depend on composer/composer package)
- Used both at plugin-time and at runtime by Maho itself
- Key methods:
  - `getInstalledPackages()` - Returns packages with types: `maho-source`, `maho-module`, or `magento-module`
  - `generateIncludePaths()` - Builds include paths for code pools (local/community/core/lib) with the highest priority first
  - `generatePsr0()` - Scans code pools to create PSR-0 prefix mappings
  - `generateClassMap()` - Maps non-PSR classes (controllers, Mage.php, Maho.php)
  - `generateIncludeFiles()` - Finds files requiring explicit inclusion (functions.php, bootstrap.php, app/etc/includes/*.php)
  - `globPackages()` - Search across all Maho packages with glob patterns

**ModmanPlugin** (src/ModmanPlugin.php)
- Hooks into package install/update/uninstall events
- Parses `modman` files from installed modules (or falls back to `extra.map` array)
- Creates symlinks in `vendor/mahocommerce/maho-modman-symlinks/{package}` (avoids polluting project tree)
- Supports modman commands: `@import` for recursive parsing, `@shell` (skipped)
- Handles wildcards in source paths
- Conflict detection prevents overlapping symlink targets

**FileCopyPlugin** (src/FileCopyPlugin.php)
- Hooks into `POST_INSTALL_CMD`, `POST_UPDATE_CMD`, `POST_CREATE_PROJECT_CMD`
- Copies static assets from modules to project:
  - `public/`, `skin/`, `js/` folders from all `maho-module` and `magento-module` packages
  - Main Maho `public/` folder and `maho` CLI script
  - API Platform bundle assets (Swagger UI / ReDoc / GraphiQL) into `public/bundles/apiplatform/`, the equivalent of Symfony's `assets:install`
- Checks both actual vendor paths and modman symlink paths for module assets
- Overwrites the copied files on each run, except the files listed in the root `extra.maho.preserve-files`
- Copies nothing when the root package is a `maho-module` or `magento-module`, unless the root sets `extra.maho.publish-assets` to `true`

**AttributeCompiler** (src/AttributeCompiler.php)
- Scans the classes of the active modules (from `app/etc/modules/*.xml`) in all packages, including the root package
- Compiles the `#[Maho\Config\...]` attributes (observers, cron jobs, message handlers, routes, Tailwind sources) into `vendor/composer/maho_attributes.php`
- Compiles the routes into `vendor/composer/maho_url_matcher.php` and `vendor/composer/maho_url_generator.php`
- Points the routes of a controller at its most-derived subclass that declares no `#[Route]`

**ApiPermissionCompiler** (src/ApiPermissionCompiler.php)
- Compiles the `#[Maho\Config\ApiResource]` attributes into `vendor/composer/maho_api_permissions.php`
- Must run after `AttributeCompiler` in the same process, because it reuses its module filter

### Package Resolution Order

**AutoloadRuntime.getInstalledPackages()** returns packages in this order:
1. `maho-source` packages (e.g., core Maho)
2. Module packages A-Z (`maho-module`, `magento-module`)
3. Root package last

**AutoloadRuntime.generateIncludePaths()** intentionally reverses this order so local files take precedence over vendor files.

### Code Pool Structure

Maho follows Magento 1's code pool pattern:
- `app/code/local/` - Project-specific code (highest priority)
- `app/code/community/` - Third-party modules
- `app/code/core/` - Core framework code
- `lib/` - Library code (in Maho: `Maho.php`, `Maho\*` and `MahoCLI\*`)

PSR-0 prefixes are generated by scanning `{codepool}/{Namespace}/{Module}/` to create `{Namespace}_{Module}_` prefixes.

### Modman File Format

The modman file format defines source-to-target mappings:
```
# Basic mapping
src/path target/path

# Wildcards
skin/frontend/default/modern/* skin/frontend/default/modern/

# Import the modman file of a subdirectory, with an optional target prefix
@import subdirectory target-prefix

# Shell commands (skipped by this plugin)
@shell command
```

## Important Notes

- `AutoloadRuntime` must remain standalone (no composer/composer dependencies) as it's also used at Maho runtime
- Symlinks created by `ModmanPlugin` go to `vendor/mahocommerce/maho-modman-symlinks/` to keep project tree clean
- `FileCopyPlugin` uses a static flag to ensure it only runs once per Composer invocation
- PHPStan is configured to level 10 with strict rules and bleeding edge features
