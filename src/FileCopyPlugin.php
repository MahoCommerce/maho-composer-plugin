<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class FileCopyPlugin implements PluginInterface, EventSubscriberInterface
{
    private static bool $hasRun = false;

    /** @var array<string> */
    private array $preserveFiles = [];

    private string $projectDir = '';

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
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'onPostCmd',
        ];
    }

    public function onPostCmd(Event $event): void
    {
        if (self::$hasRun) {
            return;
        }

        self::$hasRun = true;

        $io = $event->getIO();
        $composer = $event->getComposer();
        /** @var string */
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $projectDir = getcwd();
        if ($projectDir === false) {
            throw new \RuntimeException('Unable to determine current working directory');
        }
        $this->projectDir = $projectDir;

        // Get preserve-files configuration
        $extra = $composer->getPackage()->getExtra();
        if (isset($extra['maho']) && is_array($extra['maho']) && isset($extra['maho']['preserve-files']) && is_array($extra['maho']['preserve-files'])) {
            // Validate that all entries are strings
            $preserveFilesCandidate = $extra['maho']['preserve-files'];
            foreach ($preserveFilesCandidate as $file) {
                if (is_string($file)) {
                    $this->preserveFiles[] = $file;
                }
            }
        }

        // This has to be done for child projects, the ones using maho as a dependency
        if (file_exists("$vendorDir/mahocommerce/maho")) {
            $this->copyDirectory("$vendorDir/mahocommerce/maho/public", "$projectDir/public", $io);
            copy("$vendorDir/mahocommerce/maho/maho", './maho');
            chmod('./maho', 0744);
        }

        // This has to be done for all projects
        if (file_exists("$vendorDir/tinymce/tinymce")) {
            $this->copyDirectory("$vendorDir/tinymce/tinymce", "$projectDir/public/js/tinymce", $io);
        }

        if (file_exists("$vendorDir/mklkj/tinymce-i18n/langs6")) {
            $this->copyDirectory("$vendorDir/mklkj/tinymce-i18n/langs6", "$projectDir/public/js/tinymce/langs", $io);
        }

        // Get all maho modules
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $packages = $localRepo->getPackages();
        $mahoModules = array_filter($packages, function($package) {
            return in_array($package->getType(), ['magento-module', 'maho-module'], true);
        });

        // Copy public folders from module packages
        foreach ($mahoModules as $package) {
            $packageName = $package->getName();
            $packagePath = file_exists("$vendorDir/mahocommerce/maho-modman-symlinks/$packageName")
                ? "$vendorDir/mahocommerce/maho-modman-symlinks/$packageName"
                : "$vendorDir/$packageName";

            if (file_exists("$packagePath/public")) {
                $this->copyDirectory("$packagePath/public", "$projectDir/public", $io);
            }

            if (file_exists("$packagePath/skin")) {
                $this->copyDirectory("$packagePath/skin", "$projectDir/public/skin", $io);
            }

            if (file_exists("$packagePath/js")) {
                $this->copyDirectory("$packagePath/js", "$projectDir/public/js", $io);
            }
        }
    }

    private function copyDirectory(string $src, string $dst, IOInterface $io): void
    {
        if (!is_dir($src)) {
            $io->write("Source directory does not exist: $src");
            return;
        }

        if (($dir = opendir($src)) === false) {
            $io->write("Source directory could not be opened: $src");
            return;
        }
        @mkdir($dst, 0777, true);
        while (false !== ($file = readdir($dir))) {
            if (($file !== '.') && ($file !== '..')) {
                $srcPath = $src . '/' . $file;
                $dstPath = $dst . '/' . $file;

                if (is_dir($srcPath)) {
                    $this->copyDirectory($srcPath, $dstPath, $io);
                } else {
                    // Check if this file should be preserved
                    $relativePath = str_replace($this->projectDir . '/', '', $dstPath);
                    $shouldPreserve = in_array($relativePath, $this->preserveFiles, true) && file_exists($dstPath);

                    if ($shouldPreserve) {
                        $io->write("  - Skipping preserved file: $relativePath", true, IOInterface::VERBOSE);
                    } else {
                        copy($srcPath, $dstPath);
                    }
                }
            }
        }
        closedir($dir);
    }
}
