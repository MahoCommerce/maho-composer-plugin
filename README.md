# Composer Plugin for Maho

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/MahoCommerce/maho-composer-plugin)

## Configuration

The plugin reads all its options from the `extra` key of a `composer.json` file.
All options are optional.

### `extra.maho.publish-assets`

Read from the **root** package. A boolean. The default is `false`.

A module repository is not a deployment target. When you run Composer inside a
module repository, the plugin does not copy the Maho `public/` tree, the `maho`
CLI, or the assets of other modules into it. This keeps the working copy clean.

Set this option to `true` to publish the assets anyway. Use it when a module
repository must also work as a test store.

```json
{
    "type": "maho-module",
    "extra": {
        "maho": {
            "publish-assets": true
        }
    }
}
```

This option has no effect on a project. A project always gets the assets.

### `extra.maho.preserve-files`

Read from the **root** package. An array of strings.

Each string is a file path. The path is relative to the project directory. The
plugin does not overwrite a listed file if the file already exists. Use it to
protect a file that you changed in the project.

```json
{
    "extra": {
        "maho": {
            "preserve-files": [
                "public/robots.txt",
                "public/favicon.ico"
            ]
        }
    }
}
```

### `extra.map`

Read from a **module** package. An array of `[source, target]` pairs.

The plugin uses this option only when the module has no `modman` file. It gives
compatibility with `Cotya/magento-composer-installer`. A `modman` file is the
preferred format.

```json
{
    "type": "maho-module",
    "extra": {
        "map": [
            ["src/app/code/community/Acme/Example", "app/code/community/Acme/Example"],
            ["src/app/etc/modules/Acme_Example.xml", "app/etc/modules/Acme_Example.xml"]
        ]
    }
}
```
