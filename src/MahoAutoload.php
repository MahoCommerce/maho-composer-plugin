<?php

namespace Maho;

use Composer\InstalledVersions;

class MahoAutoload
{
    /** @var array<string, array<string, bool|string>>|null  */
    protected static ?array $modules = null;

    /** @var string[]|null  */
    protected static ?array $paths = null;

    /**
     * @return array<string, array<string, bool|string>>
     */
    public static function getInstalledModules(string $projectDir): array
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
                    $module['isChildProject'] = $module['path'] !== realpath($projectDir);
                    self::$modules = [$package => $module] + self::$modules;
                } else {
                    self::$modules[$package] = $module;
                }
            }
        }

        return self::$modules;
    }

    /**
     * @return string[]
     */
    public static function generatePaths(string $projectDir): array
    {
        if (self::$paths !== null) {
            return self::$paths;
        }
        self::$paths = [];

        $modules = self::getInstalledModules($projectDir);

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

        $addIfExists($projectDir, 'app/code/local');
        $addIfExists($projectDir, 'app/code/community');

        if (isset($modules['mahocommerce/maho']) && $modules['mahocommerce/maho']['isChildProject']) {
            $addIfExists($projectDir, 'app/code/core');
            $addIfExists($projectDir, 'lib');
        }

        foreach ($modules as $module => $info) {
            if ($module === 'mahocommerce/maho') {
                continue;
            }
            foreach (array_keys($codePools) as $codePool) {
                $addIfExists($info['path'], $codePool);
            }
        }

        if (isset($modules['mahocommerce/maho']) && $modules['mahocommerce/maho']['isChildProject']) {
            $addIfExists($modules['mahocommerce/maho']['path'], 'app/code/core');
            $addIfExists($modules['mahocommerce/maho']['path'], 'lib');
        } else {
            $addIfExists($projectDir, 'app/code/core');
            $addIfExists($projectDir, 'lib');
        }

        return self::$paths = array_merge(...array_values($codePools));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function generatePsr0(string $projectDir): array
    {
        $paths = self::generatePaths($projectDir);

        $prefixes = [];
        foreach ($paths as $path) {
            foreach (glob("$path/*/*") ?: [] as $file) {
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

    /**
     * @return string[]
     */
    public static function generateControllerClassMap(string $projectDir): array
    {
        $paths = self::generatePaths($projectDir);

        $classmaps = [];
        foreach ($paths as $path) {
            foreach (glob("$path/*/*/controllers", GLOB_ONLYDIR) ?: [] as $dir) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
                foreach ($files as $file) {
                    if (!$file->isFile() || !str_ends_with($file->getFileName(), 'Controller.php')) {
                        continue;
                    }
                    $classname = substr($file->getPathname(), strlen($path) + 1, -4);
                    $classname = str_replace('/controllers/', '/', $classname);
                    $classname = str_replace('/', '_', $classname);
                    if (!isset($classmaps[$classname])) {
                        $classmaps[$classname] = $file->getPathname();
                    }
                 }
            }
        }

        return $classmaps;
    }
}
