<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class AutoloadPlugin implements PluginInterface, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        // This method is called when the plugin is activated
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        // This method is called when the plugin is deactivated
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // This method is called when the plugin is uninstalled
    }

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'onPreAutoloadDumpCmd',
        ];
    }

    public function onPreAutoloadDumpCmd(Event $event): void
    {
        $composer = $event->getComposer();
        /** @var RootPackage */
        $rootPackage = $composer->getPackage();
        $autoloadDefinition = $rootPackage->getAutoload();

        $includePaths = AutoloadRuntime::generateIncludePaths();

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
