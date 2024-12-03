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
