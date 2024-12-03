<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Composer\InstalledVersions;

/**
 * @phpstan-type Package array{name: string, type: string, path: string}
 * @phpstan-type PackageArray array<string, Package>
 */
final class AutoloadRuntime
{
    /** @var ?PackageArray */
    private static ?array $installedPackages = null;

    /** @var ?list<string> */
    private static ?array $includePaths = null;

    /**
     * @return PackageArray
     */
    public static function getInstalledPackages(): array
    {
        if (self::$installedPackages !== null) {
            return self::$installedPackages;
        }

        $rootPackage = null;
        $mahoSourcePackages = [];
        $modulePackages = [];

        foreach (InstalledVersions::getAllRawData() as $dataset) {
            if ($dataset['root']['name'] === 'composer/composer') {
                continue;
            }
            $rootPackage ??= [
                'name' => $dataset['root']['name'],
                'type' => $dataset['root']['type'],
                'path' => realpath($dataset['root']['install_path']),
            ];
            foreach ($dataset['versions'] as $package => $info) {
                if (!isset($info['type']) || !isset($info['install_path'])) {
                    continue;
                }
                $info = [
                    'name' => $package,
                    'type' => $info['type'],
                    'path' => realpath($info['install_path']),
                ];
                if ($info['path'] === false) {
                    continue;
                }
                if ($info['type'] === 'maho-source') {
                    $mahoSourcePackages[$package] = $info;
                } elseif (in_array($info['type'], ['maho-module', 'magento-module'], true)) {
                    $modulePackages[$package] = $info;
                }
            }
        }

        return self::$installedPackages = [
            ...$mahoSourcePackages,
            ...$modulePackages,
            'root' => $rootPackage,
        ];
    }

    /**
     * @return list<string>
     */
    public static function globPackages(string $pattern, int $flags = 0): array
    {
        $packages = self::getInstalledPackages();
        $pattern = '/' . ltrim($pattern, '/');
        $results = [];

        foreach ($packages as $info) {
            $glob = glob($info['path'] . $pattern, $flags);
            if ($glob !== false) {
                array_push($results, ...$glob);
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    public static function generateIncludePaths(): array
    {
        if (self::$includePaths !== null) {
            return self::$includePaths;
        }
        self::$includePaths = [];

        $codePools = [
            'app/code/local' => [],
            'app/code/community' => [],
            'app/code/core' => [],
            'lib' => [],
        ];

        $addIfExists = function (string $path, string $codePool) use (&$codePools) {
            if (is_dir("$path/$codePool")) {
                $codePools[$codePool][] = "$path/$codePool";
            }
        };

        $packages = self::getInstalledPackages();

        foreach (array_reverse($packages) as $package => $info) {
            if ($package === 'root' && $info['type'] === 'maho-source') {
                $addIfExists($info['path'], 'app/code/local');
                $addIfExists($info['path'], 'app/code/community');
            } else {
                $addIfExists($info['path'], 'app/code/local');
                $addIfExists($info['path'], 'app/code/community');
                $addIfExists($info['path'], 'app/code/core');
                $addIfExists($info['path'], 'lib');
            }
        }

        return self::$includePaths = array_values(array_unique(array_merge(...array_values($codePools))));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function generatePsr0(): array
    {
        $paths = self::generateIncludePaths();
        $prefixes = [];

        foreach ($paths as $path) {
            $glob = glob("$path/*/*");
            if ($glob === false) {
                continue;
            }
            foreach ($glob as $file) {
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
     * @return array<string, string>
     */
    public static function generateClassMap(): array
    {
        $packages = self::getInstalledPackages();
        $classMap = [];

        foreach ($packages as $package => $info) {
            if ($info['type'] === 'maho-source') {
                if (file_exists($info['path'] . '/app/Mage.php')) {
                    $classMap['Mage'] = $info['path'] . '/app/Mage.php';
                }
                if (file_exists($info['path'] . '/lib/Maho.php')) {
                    $classMap['Maho'] = $info['path'] . '/lib/Maho.php';
                }
            }
        }

        foreach (self::globPackages('/app/code/*/*/*', GLOB_ONLYDIR) as $moduleDir) {

            $modulePrefix = implode('_', array_slice(explode('/', $moduleDir), -2));

            $controllersDir = "$moduleDir/controllers";
            if (is_dir($controllersDir)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($controllersDir));
                /** @var \SplFileInfo $file */
                foreach ($files as $file) {
                    if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Controller.php')) {
                        continue;
                    }
                    $className = substr($file->getPathname(), strlen($controllersDir), -4);
                    $className = str_replace('/', '_', $className);
                    $className = $modulePrefix . $className;
                    $classMap[$className] = $file->getPathname();
                }
            }
        }

        return $classMap;
    }

    /**
     * @return list<string>
     */
    public static function generateIncludeFiles(): array
    {
        $packages = self::getInstalledPackages();
        $files = [];
        $filesCore = [];

        foreach ($packages as $package => $info) {
            if ($info['type'] === 'maho-source') {
                if (file_exists($info['path'] . '/app/code/core/Mage/Core/functions.php')) {
                    $filesCore['functions'] = $info['path'] . '/app/code/core/Mage/Core/functions.php';
                }
                if (file_exists($info['path'] . '/app/bootstrap.php')) {
                    $filesCore['bootstrap'] = $info['path'] . '/app/bootstrap.php';
                }
            }
        }

        foreach (self::globPackages('/app/etc/includes/*.php') as $file) {
            $files[] = $file;
        }
        if (isset($filesCore['functions'])) {
            array_unshift($files, $filesCore['functions']);
        }
        if (isset($filesCore['bootstrap'])) {
            $files[] = $filesCore['bootstrap'];
        }

        return $files;
    }
}
