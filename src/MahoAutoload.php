<?php

namespace Maho;

use Composer\InstalledVersions;

class MahoAutoload
{
    protected static $modules = null;

    public static function getInstalledModules(): array
    {
        if (self::$modules !== null) {
            return self::$modules;
        }
        self::$modules = [];

        $datasets = InstalledVersions::getAllRawData();

        foreach ($datasets as $dataset) {
            foreach ($dataset['versions'] as $package => $info) {
                if (isset(self::$modules[$package])) {
                    continue;
                }
                if (!isset($info['install_path'])) {
                    continue;
                }
                if (!in_array($info['type'], ['maho-source', 'maho-module', 'magento-module'])) {
                    continue;
                }
                $module = [
                    'type' => $info['type'],
                    'path' => realpath($info['install_path']),
                ];
                if ($package === 'mahocommerce/maho') {
                    self::$modules = [$package => $module] + self::$modules;
                } else {
                    self::$modules[$package] = $module;
                }
            }
        }

        return self::$modules;
    }

    public static function generatePaths(string $BP): array
    {
        $modules = self::getInstalledModules();

        $MAHO_FRAMEWORK_DIR = $modules['mahocommerce/maho']['path'];
        $MAHO_IS_CHILD_PROJECT = $MAHO_FRAMEWORK_DIR !== realpath($BP);

        $codePools = [
            'app/code/local' => [],
            'app/code/community' => [],
            'app/code/core' => [],
            'lib' => [],
        ];

        $addIfExists = function ($path, $codePool) use (&$codePools) {
            if (is_dir("$path/$codePool")) {
                $codePools[$codePool][] = "$path/$codePool";
            }
        };

        $addIfExists($BP, 'app/code/local');
        $addIfExists($BP, 'app/code/community');
        if ($MAHO_IS_CHILD_PROJECT) {
            $addIfExists($BP, 'app/code/core');
            $addIfExists($BP, 'lib');
        }

        foreach ($modules as $module => $info) {
            if ($module === 'mahocommerce/maho') {
                continue;
            }
            $path = $info['path'];
            foreach (array_keys($codePools) as $codePool) {
                $addIfExists($path, $codePool);
            }
        }

        if ($MAHO_IS_CHILD_PROJECT) {
            $addIfExists($MAHO_FRAMEWORK_DIR, 'app/code/core');
            $addIfExists($MAHO_FRAMEWORK_DIR, 'lib');
        } else {
            $addIfExists($BP, 'app/code/core');
            $addIfExists($BP, 'lib');
        }

        return array_merge(...array_values($codePools));
    }

    public static function generatePsr0(string $BP): array
    {
        $paths = self::generatePaths($BP);

        $prefixes = [];
        foreach ($paths as $path) {
            foreach (glob("$path/*/*") as $file) {
                $prefix = str_replace('/', '_', substr($file, strlen($path) + 1));
                if (is_file($file) && str_ends_with($file, '.php')) {
                    $prefix = str_replace('.php', '', $prefix);
                } else if (is_dir($file)) {
                    $prefix .= '_';
                } else {
                    continue;
                }
                $prefixes[$prefix] ??= [];
                $prefixes[$prefix][] = $path;
            }
        }

        return $prefixes;
    }
}
