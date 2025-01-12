<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Composer\InstalledVersions;

/**
 * This class handles finding packages and the actual building of include paths, PSR-0 prefixes, classmaps, etc
 *
 * It is separate from AutoloadPlugin since the main Maho repo also calls its methods during runtime where we don't have
 * the composer/composer package installed, thus referencing classes like Composer\Plugin\PluginInterface would fail.
 *
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
     * Return an array of packages installed by Composer with a package type of maho-source, maho-module, or magento-module.
     *
     * Array will include maho-source packages first, then modules A-Z, then the root package last.
     *
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
            if (($rootDir = realpath($dataset['root']['install_path'])) === false) {
                continue;
            }
            $rootPackage = [
                'name' => $dataset['root']['name'],
                'type' => $dataset['root']['type'],
                'path' => $rootDir,
            ];
            foreach ($dataset['versions'] as $package => $info) {
                if (!isset($info['type']) || !isset($info['install_path'])) {
                    continue;
                }
                if (($packageDir = realpath($info['install_path'])) === false) {
                    continue;
                }
                if (($symlinkDir = realpath("$rootDir/vendor/mahocommerce/maho-modman-symlinks/$package")) !== false) {
                    $packageDir = $symlinkDir;
                }
                $info = [
                    'name' => $package,
                    'type' => $info['type'],
                    'path' => $packageDir,
                ];
                if ($info['type'] === 'maho-source') {
                    $mahoSourcePackages[$package] = $info;
                } elseif (in_array($info['type'], ['maho-module', 'magento-module'], true)) {
                    $modulePackages[$package] = $info;
                }
            }
            break;
        }

        if ($rootPackage === null) {
            return [];
        }

        return self::$installedPackages = [
            ...$mahoSourcePackages,
            ...$modulePackages,
            'root' => $rootPackage,
        ];
    }

    /**
     * Search for files in Maho and module packages with a glob pattern.
     *
     * @param $pattern A glob pattern to search for, i.e. /app/etc/modules/*.xml
     * @param $flags Any of the GLOB_* constants
     * @see https://www.php.net/manual/en/filesystem.constants.php#constant.glob-available-flags
     * @return list<string> An array of absolute filenames matching the pattern
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
     * Generate a list of include paths for backwards compatibility with OpenMage / Magento 1.9.
     *
     * Paths will include the root package first, then modules Z-A, then maho-source packages last.
     * This is intentionally the reverse order of getInstalledPackages() so local files are matched first.
     *
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
     * Scan code pools to build PSR-0 mapping to provide to Composer's autoloader.
     *
     * Example: [
     *     "Mage_Core_" => ["vendor/mahocommerce/maho/app/code/core"],
     *     "Mage_Customer_" => ["app/code/local", "vendor/mahocommerce/maho/app/code/core"],
     *     "Zend_" => ["vendor/shardj/zf1-future/library"],
     *     // ...
     * ];
     *
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
     * Build a class map for files that do not follow PSR-0 or PSR-4 standards, such as controllers and app/Mage.php.
     *
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
            // Get the prefix of the class names in this file, i.e. Mage_Adminhtml
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
     * Scan for files that can't be autoloaded, such as functions.php, bootstrap.php, and any app/etc/includes/*.php file.
     *
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
