<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Composer\IO\IOInterface;
use Maho\Attributes\CronJob;
use Maho\Attributes\Observer;
use ReflectionClass;
use ReflectionMethod;

final class AttributeCompiler
{
    private const ALLOWED_AREAS = ['global', 'frontend', 'adminhtml', 'crontab'];

    /**
     * @var array{
     *     observers: array<string, array<string, list<array{name: string, module: string, class: string, method: string, type: string, args: array<string, mixed>}>>>,
     *     crontab: array<string, array{class: string, method: string, schedule: ?string, config_path: ?string}>,
     *     replaces?: list<array{target: string, area: string, event: string}>
     * }
     */
    private static array $data;

    /**
     * Compile PHP attributes into a cached array file.
     *
     * @param string $outputDir Directory where attributes.php will be written
     */
    public static function compile(string $outputDir, IOInterface $io): void
    {
        self::$data = [
            'observers' => [
                'global' => [],
                'frontend' => [],
                'adminhtml' => [],
                'crontab' => [],
            ],
            'crontab' => [],
        ];

        $replaces = [];

        foreach (self::scanClasses() as $className => $filePath) {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                continue;
            }

            if (strpos($contents, 'Maho\Attributes\Observer') === false && strpos($contents, 'Maho\Attributes\CronJob') === false) {
                continue;
            }

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                self::processObserverAttributes($method, $className, $replaces, $io);
                self::processCronJobAttributes($method, $className, $io);
            }
        }

        self::applyReplaces($replaces);
        self::writeOutput($outputDir, $io);
    }

    /**
     * Scan module directories for PHP classes using Composer's ClassMapGenerator.
     *
     * Only scans app/code/{pool}/{Namespace}/{Module}/ directories.
     * Classes in lib/ or other non-standard locations are not included.
     *
     * Later packages overwrite earlier ones so that local code pool
     * overrides take precedence over core/community classes.
     *
     * @return array<class-string, string>
     */
    private static function scanClasses(): array
    {
        $classes = [];

        foreach (AutoloadRuntime::globPackages('/app/code/*/*/*', GLOB_ONLYDIR) as $moduleDir) {
            foreach (ClassMapGenerator::createMap($moduleDir) as $class => $file) {
                $classes[$class] = $file;
            }
        }

        return $classes;
    }

    /**
     * @param list<array{target: string, area: string, event: string}> $replaces
     */
    private static function processObserverAttributes(
        ReflectionMethod $method,
        string $className,
        array &$replaces,
        IOInterface $io,
    ): void {
        $attributes = $method->getAttributes(Observer::class);
        foreach ($attributes as $attribute) {
            try {
                $observer = $attribute->newInstance();
            } catch (\Throwable $e) {
                $io->writeError(sprintf(
                    '  <warning>Skipping Observer attribute on %s::%s: %s</warning>',
                    $className,
                    $method->getName(),
                    $e->getMessage(),
                ));
                continue;
            }

            $name = $observer->name ?? $className . '::' . $method->getName();
            $areas = array_map('trim', explode(',', $observer->area));
            $event = strtolower($observer->event);

            $entry = [
                'name' => $name,
                'module' => self::extractModuleName($className),
                'class' => $className,
                'method' => $method->getName(),
                'type' => $observer->type,
                'args' => $observer->args,
            ];

            foreach ($areas as $area) {
                if (!in_array($area, self::ALLOWED_AREAS, true)) {
                    $io->writeError(sprintf(
                        '  <warning>Invalid observer area "%s" on %s::%s, skipping</warning>',
                        $area,
                        $className,
                        $method->getName(),
                    ));
                    continue;
                }

                self::$data['observers'][$area][$event] ??= [];

                foreach (self::$data['observers'][$area][$event] as $existing) {
                    if ($existing['name'] === $name) {
                        $io->writeError(sprintf(
                            '  <warning>Duplicate observer name "%s" on %s/%s</warning>',
                            $name,
                            $area,
                            $event,
                        ));
                        break;
                    }
                }

                self::$data['observers'][$area][$event][] = $entry;

                if ($observer->replaces !== null) {
                    $replaces[] = [
                        'target' => $observer->replaces,
                        'area' => $area,
                        'event' => $event,
                    ];
                }
            }
        }
    }

    private static function processCronJobAttributes(
        ReflectionMethod $method,
        string $className,
        IOInterface $io,
    ): void {
        $attributes = $method->getAttributes(CronJob::class);
        foreach ($attributes as $attribute) {
            try {
                $cronJob = $attribute->newInstance();
            } catch (\Throwable $e) {
                $io->writeError(sprintf(
                    '  <warning>Skipping CronJob attribute on %s::%s: %s</warning>',
                    $className,
                    $method->getName(),
                    $e->getMessage(),
                ));
                continue;
            }

            if ($cronJob->schedule === null && $cronJob->configPath === null) {
                $io->writeError(sprintf(
                    '  <warning>CronJob on %s::%s has neither schedule nor config_path, skipping</warning>',
                    $className,
                    $method->getName(),
                ));
                continue;
            }

            $name = $cronJob->name ?? self::generateCronJobName($className, $method->getName());

            if (isset(self::$data['crontab'][$name])) {
                $io->writeError(sprintf(
                    '  <warning>CronJob name "%s" on %s::%s overwrites %s::%s</warning>',
                    $name,
                    $className,
                    $method->getName(),
                    self::$data['crontab'][$name]['class'],
                    self::$data['crontab'][$name]['method'],
                ));
            }

            self::$data['crontab'][$name] = [
                'class' => $className,
                'method' => $method->getName(),
                'schedule' => $cronJob->schedule,
                'config_path' => $cronJob->configPath,
            ];
        }
    }

    private static function generateCronJobName(string $className, string $methodName): string
    {
        $prefix = $className;
        if (str_starts_with($prefix, 'Mage_')) {
            $prefix = substr($prefix, 5);
        }
        $prefix = str_replace('_Model_', '_', $prefix);
        $prefix = strtolower(self::camelToSnake($prefix));
        $method = strtolower(self::camelToSnake($methodName));
        return $prefix . '_' . $method;
    }

    private static function camelToSnake(string $input): string
    {
        $result = '';
        $length = strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            if ($i > 0 && ctype_upper($char) && ctype_lower($input[$i - 1])) {
                $result .= '_';
            }
            $result .= $char;
        }
        return $result;
    }

    /**
     * Remove observers targeted by `replaces` directives.
     *
     * Only resolves attribute→attribute replaces at compile time.
     * Attribute→XML replaces are stored in the compiled output and resolved at runtime.
     *
     * @param list<array{target: string, area: string, event: string}> $replaces
     */
    private static function applyReplaces(array $replaces): void
    {
        $unresolvedReplaces = [];

        foreach ($replaces as $replace) {
            $resolved = false;
            $area = $replace['area'];
            $event = $replace['event'];
            $target = $replace['target'];

            if (isset(self::$data['observers'][$area][$event])) {
                $observers = self::$data['observers'][$area][$event];
                $countBefore = count($observers);
                $observers = array_values(
                    array_filter(
                        $observers,
                        static fn (array $observer): bool => $observer['name'] !== $target,
                    ),
                );
                self::$data['observers'][$area][$event] = $observers;
                $resolved = count($observers) < $countBefore;
            }

            if (!$resolved) {
                $unresolvedReplaces[] = $replace;
            }
        }

        // Store unresolved replaces for runtime resolution against XML observers
        if ($unresolvedReplaces !== []) {
            self::$data['replaces'] = $unresolvedReplaces;
        }
    }

    private static function extractModuleName(string $className): string
    {
        // Namespace-style: Maho\SomeModule\..., Vendor\Module\... → first two segments
        if (str_contains($className, '\\')) {
            $parts = explode('\\', $className);
            if (count($parts) >= 2) {
                return $parts[0] . '\\' . $parts[1];
            }
            return $className;
        }

        // Underscore-style: Mage_Wishlist_Model_Observer, Vendor_Module_... → first two segments
        $parts = explode('_', $className);
        if (count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1];
        }

        return $className;
    }

    private static function writeOutput(string $outputDir, IOInterface $io): void
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            $io->writeError(sprintf('  <error>Failed to create directory %s</error>', $outputDir));
            return;
        }

        $content = '<?php return ' . var_export(self::$data, true) . ";\n";
        if (file_put_contents($outputDir . '/attributes.php', $content) === false) {
            $io->writeError(sprintf('  <error>Failed to write %s/attributes.php</error>', $outputDir));
        }
    }
}
