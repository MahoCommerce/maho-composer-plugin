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
        $autoloadDefinition = $rootPackage->getAutoload();

        $includePaths = AutoloadRuntime::generateIncludePaths();
        foreach ($includePaths as &$path) {
            $path = $this->filesystem->findShortestPath($rootDir, $path);
        }

        $includeFiles = AutoloadRuntime::generateIncludeFiles();
        $autoloadDefinition['files'] ??= [];
        array_unshift($autoloadDefinition['files'], ...$includeFiles);

        $classMap = AutoloadRuntime::generateClassMap();
        $autoloadDefinition['classmap'] ??= [];
        array_unshift($autoloadDefinition['classmap'], ...array_values($classMap));

        if ($event->getFlags()['optimize'] === true) {
            array_unshift($autoloadDefinition['classmap'], ...$includePaths);
        } else {
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
