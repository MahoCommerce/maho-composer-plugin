<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

/**
 * Composer plugin to hook into the PRE_AUTOLOAD_DUMP event and dynamically populate composer.json > autoload entries.
 *
 * This has the same effect as hardcoding definitions in composer.json, but dynamic generation takes into account all
 * installed packages. For example, the following entries in composer.json would be equivalent:
 *
 * "autoload": {
 *     "psr-0": {
 *         "Mage_Core_": "vendor/mahocommerce/maho/app/code/core",
 *         "Mage_Customer_": [
 *             "app/code/local",
 *             "vendor/mahocommerce/maho/app/code/core"
 *         ],
 *         "Zend_" => "vendor/shardj/zf1-future/library"
 *     },
 *     "classmap": {
 *         "Mage": "vendor/mahocommerce/maho/app/Mage.php",
 *         "Mage_Core_IndexController": "vendor/mahocommerce/maho/app/code/core/Mage/Core/controllers/IndexController.php",
 *     },
 *     "files": [
 *         "vendor/mahocommerce/maho/app/code/core/Mage/Core/functions.php",
 *         "vendor/mahocommerce/maho/app/bootstrap.php"
 *     ],
 * },
 * "paths": [
 *     "app/code/local",
 *     "vendor/some/module/app/code/community",
 *     "vendor/mahocommerce/maho/app/code/core",
 *     "vendor/mahocommerce/maho/lib"
 * ]
 *
 */
final class AutoloadPlugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private Filesystem $filesystem;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->filesystem = new Filesystem();
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'onPreAutoloadDumpCmd',
        ];
    }

    public function onPreAutoloadDumpCmd(Event $event): void
    {
        /** @var RootPackage */
        $rootPackage = $this->composer->getPackage();
        $rootDir = dirname($this->composer->getConfig()->get('vendor-dir'));

        /** @var array{psr-0?: array<string, string[]>, psr-4?: array<string, string[]>, classmap?: list<string>, files?: list<string>, exclude-from-classmap?: list<string>} */
        $autoloadDefinition = $rootPackage->getAutoload();

        // Get a list of our code pool paths, i.e. app/code/local, app/code/core, lib, etc
        $includePaths = AutoloadRuntime::generateIncludePaths();
        foreach ($includePaths as &$path) {
            // Path will originally be absolute, but we need it relative to composer.json
            $path = $this->filesystem->findShortestPath($rootDir, $path);
        }

        // Get a list of files that can't be autoloaded, i.e. bootstrap.php and functions.php
        $includeFiles = AutoloadRuntime::generateIncludeFiles();
        $autoloadDefinition['files'] ??= [];
        array_unshift($autoloadDefinition['files'], ...$includeFiles);

        // Get a list on non PSR-0 / PSR-4 classes so they can be autoloaded
        $classMap = AutoloadRuntime::generateClassMap();
        $autoloadDefinition['classmap'] ??= [];
        array_unshift($autoloadDefinition['classmap'], ...array_values($classMap));

        if ($event->getFlags()['optimize'] === true) {
            // If the optimize flag was used, tell composer to scan all of our include paths to build a complete classmap
            array_unshift($autoloadDefinition['classmap'], ...$includePaths);
        } else {
            // Otherwise, we will use PSR-0 prefixes so composer can find classes
            $psr0 = AutoloadRuntime::generatePsr0();
            $autoloadDefinition['psr-0'] ??= [];
            foreach ($psr0 as $prefix => $paths) {
                $autoloadDefinition['psr-0'][$prefix] ??= [];
                array_unshift($autoloadDefinition['psr-0'][$prefix], ...$paths);
            }
        }

        $rootPackage->setAutoload($autoloadDefinition);
        $rootPackage->setIncludePaths([...$includePaths, ...$rootPackage->getIncludePaths()]);
    }
}
