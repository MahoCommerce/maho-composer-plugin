<?php

namespace Maho;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

class MahoComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    private static $hasRun = false;

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
            ScriptEvents::POST_INSTALL_CMD => 'onPostCmd',
            ScriptEvents::POST_UPDATE_CMD => 'onPostCmd',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'onPostCmd'
        ];
    }

    public function onPostCmd(Event $event)
    {
        if (self::$hasRun) {
            return;
        }

        self::$hasRun = true;

        $io = $event->getIO();
        $composer = $event->getComposer();
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $projectDir = getcwd();

        $io->write("MahoComposerPlugin: Post-command routine called.");

        $this->copyDirectory("$vendorDir/mahocommerce/maho/pub", "$projectDir/pub", $io);
        copy("$vendorDir/mahocommerce/maho/maho", './maho');
        chmod('./maho', "u+x");

        $io->write("MahoComposerPlugin: Post-command routine completed.");
    }

    private function copyDirectory($src, $dst, $io)
    {
        if (!is_dir($src)) {
            $io->write("Source directory does not exist: $src");
            return;
        }

        $dir = opendir($src);
        @mkdir($dst, 0777, true);
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($src . '/' . $file)) {
                    $this->copyDirectory($src . '/' . $file, $dst . '/' . $file, $io);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
    }
}