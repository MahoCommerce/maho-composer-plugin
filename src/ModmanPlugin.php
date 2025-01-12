<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\DependencyResolver\Operation;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

/**
 * Composer plugin to parse modman files and create symlinks in the way Maho expects modules to be structured.
 * Symlinks will be created at vendor/mahocommerce/maho-modman-symlinks/$packageName and will not pollute the project's tree.
 */
final class ModmanPlugin implements PluginInterface, EventSubscriberInterface
{
    private IOInterface $io;
    private Composer $composer;
    private Filesystem $filesystem;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->io = $io;
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
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageUpdate',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'onPostPackageUninstall',
        ];
    }

    public function onPostPackageInstall(PackageEvent $event): void
    {
        /** @var Operation\InstallOperation */
        $operation = $event->getOperation();
        $this->undeploy($operation->getPackage());
        $this->deploy($operation->getPackage());
    }

    public function onPostPackageUpdate(PackageEvent $event): void
    {
        /** @var Operation\UpdateOperation */
        $operation = $event->getOperation();
        $this->undeploy($operation->getTargetPackage());
        $this->deploy($operation->getTargetPackage());
    }

    public function onPostPackageUninstall(PackageEvent $event): void
    {
        /** @var Operation\UninstallOperation */
        $operation = $event->getOperation();
        $this->undeploy($operation->getPackage());
    }

    private function isMahoModule(PackageInterface $package): bool
    {
        return in_array($package->getType(), ['maho-module', 'magento-module'], true);
    }

    private function getSymlinkDir(PackageInterface $package): string
    {
        return $this->composer->getConfig()->get('vendor-dir') . '/mahocommerce/maho-modman-symlinks/' . $package->getName();
    }

    private function undeploy(PackageInterface $package): void
    {
        if (!$this->isMahoModule($package)) {
            return;
        }
        $this->filesystem->removeDirectory($this->getSymlinkDir($package));
    }

    private function deploy(PackageInterface $package): void
    {
        $packageDir = $this->composer->getInstallationManager()->getInstallPath($package);
        $symlinkDir = $this->getSymlinkDir($package);

        if ($packageDir === null || !$this->isMahoModule($package)) {
            return;
        }

        // Parse modman file if exists
        $map = $this->parseModman($packageDir);

        // Fallback to extra.map array for compatibility with Cotya/magento-composer-installer
        if (count($map) === 0 && is_array($package->getExtra()['map'] ?? null)) {
            foreach ($package->getExtra()['map'] as [$source, $target]) {
                if (is_string($source) && is_string($target)) {
                    $map[] = [$source, $target];
                }
            }
        }

        // Build symlinks, with support for wildcards, and preventing accesing parent directories
        $symlinks = [];
        foreach ($map as [$source, $target]) {
            if (str_contains($source, '..') || str_contains($target, '..')) {
                continue;
            }
            if (str_contains($source, '*')) {
                $files = glob("$packageDir/$source");
                if ($files === false) {
                    continue;
                }
                foreach ($files as $file) {
                    $file = $this->filesystem->findShortestPath($packageDir, $file);
                    $symlinks[] = [$file, "$target/" . basename($file)];
                }
            } elseif ($this->filesystem::isReadable("$packageDir/$source")) {
                $symlinks[] = [$source, $target];
            }
        }

        // If all files are straight mappings (i.e. created with generate-modman), then there's nothing to do
        $unique = array_filter($symlinks, fn ($link) => $link[0] !== $link[1]);
        if (count($unique) === 0) {
            return;
        }

        $this->io->write(sprintf(
            '  - Symlinking <info>%s</info> to %s',
            $package->getName(),
            $this->filesystem->findShortestPath(Platform::getCwd(), $symlinkDir),
        ));

        // Sort symlinks by target path ASC with shortest paths first
        usort($symlinks, fn ($a, $b) => $a[1] <=> $b[1]);

        $created = [];
        foreach ($symlinks as [$source, $target]) {
            // Build full paths
            $source = "$packageDir/$source";
            $target = "$symlinkDir/$target";

            // Ensure target hasn't already been created or is in a child directory of another symlink
            $conflicts = array_filter($created, fn ($link) => str_starts_with($target, $link));
            if (count($conflicts) > 0) {
                $this->io->writeError(sprintf(
                    '  - <error>Could not symlink %s/%s -> %s because it conflicts with another target.</error>',
                    $package->getName(),
                    str_replace("$packageDir/", '', $source),
                    str_replace("$symlinkDir/", '', $target),
                ));
                continue;
            }
            $this->filesystem->ensureDirectoryExists(dirname($target));
            $this->filesystem->relativeSymlink($source, $target);
            $created[] = $target;
        }
    }

    /**
     * @return list<array{string, string}>
     */
    private function parseModman(?string $modmanDir, ?string $sourcePath = '', ?string $targetPath = ''): array
    {
        if ($modmanDir === null || !is_file("$modmanDir/modman") || !is_readable("$modmanDir/modman")) {
            return [];
        }

        $modmanFile = new \SplFileObject("$modmanDir/modman");

        // Enforce trailing slash
        $sourcePath = $sourcePath === '' ? '' : "$sourcePath/";
        $targetPath = $targetPath === '' ? '' : "$targetPath/";

        $map = [];

        while (!$modmanFile->eof()) {
            // Get line contents disregarding any comment
            $line = preg_replace('/\s*#.*/', '', trim($modmanFile->fgets()));

            // Split line and make sure we have at least two parts
            $parts = preg_split('/\s+/', $line ?? '');
            if ($parts === false || count($parts) < 2) {
                continue;
            }

            // Check if this line starts with command
            $command = str_starts_with($parts[0], '@') ? array_shift($parts) : null;

            // If shell command, skip this line and subsequent lines if ending with a backslash
            if ($command === '@shell') {
                while (!$modmanFile->eof()) {
                    /** @phpstan-ignore argument.type */
                    $line = preg_replace('/\s*#.*/', '', trim($modmanFile->current()));
                    if ($line === null || !str_ends_with($line, '\\')) {
                        break;
                    }
                    $modmanFile->next();
                }
                continue;
            }

            // Normalize paths and strip any leading or trailing slashes
            $parts = array_map(fn ($part) => trim($this->filesystem->normalizePath($part), '/'), $parts);

            // If we have an import command, recurse while noting that sourcePath and targetPath may be different
            if ($command === '@import') {
                $newSourcePath = $sourcePath . $parts[0];
                $newTargetPath = $targetPath . ($parts[1] ?? '');
                array_push($map, ...self::parseModman("$modmanDir/{$parts[0]}", $newSourcePath, $newTargetPath));
            }

            // If no other command, simply add to our map
            elseif ($command === null) {
                $map[] = [$sourcePath . $parts[0], $targetPath . $parts[1]];
            }
        }

        return $map;
    }
}
