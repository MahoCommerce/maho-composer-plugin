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
        $dataset = end($datasets); // We only care about the packages installed to root vendor dir

        foreach ($dataset['versions'] as $package => $info) {
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

        return self::$modules;
    }

    public static function generatePaths(string $BP): array
    {
        $datasets = InstalledVersions::getAllRawData();
        $dataset = end($datasets); // We only care about the packages installed to root vendor dir

        $MAHO_IS_CHILD_PROJECT = $dataset['root']['name'] !== 'mahocommerce/maho';
        $MAHO_FRAMEWORK_DIR = realpath($dataset['versions']['mahocommerce/maho']['install_path']);

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

        foreach (self::getInstalledModules() as $module => $info) {
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
