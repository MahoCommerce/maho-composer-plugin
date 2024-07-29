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
        $io = $event->getIO();
        $composer = $event->getComposer();
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $projectDir = getcwd();
        $pubDir = $projectDir . '/pub';

        $io->write("MahoComposerPlugin: Post-install routine called.");

        function copyDirectory($src, $dst, $io)
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
                        copyDirectory($src . '/' . $file, $dst . '/' . $file, $io);
                    } else {
                        copy($src . '/' . $file, $dst . '/' . $file);
                    }
                }
            }
            closedir($dir);
        }

        // Step 1: Copy vendor/mahocommerce/maho/pub to the project main folder
        $mahoPubDir = $vendorDir . '/mahocommerce/maho/pub';
        if (is_dir($mahoPubDir)) {
            $io->write("Copying $mahoPubDir to $projectDir");
            copyDirectory($mahoPubDir, $projectDir, $io);
        } else {
            $io->write("Directory not found: $mahoPubDir");
        }

        // Step 2: Search for 'skin' directories
        $io->write("Searching for 'skin' directories in the vendor folder...");
        $skinDirs = glob($vendorDir . '/*/*/skin', GLOB_ONLYDIR);

        foreach ($skinDirs as $sourceDir) {
            $relativePath = substr($sourceDir, strlen($vendorDir) + 1);
            $targetDir = $pubDir . '/' . $relativePath;

            $io->write("Found 'skin' directory: $sourceDir");
            $io->write("Copying to: $targetDir");

            copyDirectory($sourceDir, $targetDir, $io);
        }

        // Step 3: Search for 'js' directories
        $io->write("Searching for 'js' directories in the vendor folder...");
        $jsDirs = glob($vendorDir . '/*/*/js', GLOB_ONLYDIR);

        foreach ($jsDirs as $sourceDir) {
            $relativePath = substr($sourceDir, strlen($vendorDir) + 1);
            $targetDir = $pubDir . '/' . $relativePath;

            $io->write("Found 'js' directory: $sourceDir");
            $io->write("Copying to: $targetDir");

            copyDirectory($sourceDir, $targetDir, $io);
        }

        $io->write("MahoComposerPlugin: Post-install routine completed.");
    }
}