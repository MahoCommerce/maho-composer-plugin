<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Maho\Attributes\CronJob;
use Maho\Attributes\Observer;
use ReflectionClass;
use ReflectionMethod;

class AttributeCompiler
{
    private const SCAN_PATTERNS = [
        '/app/code/*/*/*/Model/*.php',
        '/app/code/*/*/*/Helper/*.php',
        '/app/code/*/*/*/Block/*.php',
    ];

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
    public static function compile(string $outputDir): void
    {
        self::$data = [
            'observers' => [
                'global' => [],
                'frontend' => [],
                'adminhtml' => [],
            ],
            'crontab' => [],
        ];

        $replaces = [];

        foreach (self::scanFiles() as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            if (strpos($contents, 'Maho\Attributes\Observer') === false && strpos($contents, 'Maho\Attributes\CronJob') === false) {
                continue;
            }

            $className = self::extractClassName($contents);
            if ($className === null) {
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

                self::processObserverAttributes($method, $className, $replaces);
                self::processCronJobAttributes($method, $className);
            }
        }

        self::applyReplaces($replaces);
        self::writeOutput($outputDir);
    }

    /**
     * @return list<string>
     */
    private static function scanFiles(): array
    {
        $files = [];
        foreach (self::SCAN_PATTERNS as $pattern) {
            array_push($files, ...AutoloadRuntime::globPackages($pattern));
        }
        return array_values(array_unique($files));
    }

    private static function extractClassName(string $contents): ?string
    {
        if (preg_match('/^(?:final\s+|readonly\s+|abstract\s+)*class\s+(\w+)/m', $contents, $classMatch) !== 1) {
            return null;
        }

        $className = $classMatch[1];

        if (preg_match('/^namespace\s+([^\s;]+)/m', $contents, $nsMatch) === 1) {
            $className = $nsMatch[1] . '\\' . $className;
        }

        return $className;
    }

    /**
     * @param list<array{target: string, area: string, event: string}> $replaces
     */
    private static function processObserverAttributes(
        ReflectionMethod $method,
        string $className,
        array &$replaces,
    ): void {
        $attributes = $method->getAttributes(Observer::class);
        foreach ($attributes as $attribute) {
            $observer = $attribute->newInstance();
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
                self::$data['observers'][$area][$event] ??= [];
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
    ): void {
        $attributes = $method->getAttributes(CronJob::class);
        foreach ($attributes as $attribute) {
            $cronJob = $attribute->newInstance();
            $name = $cronJob->name ?? self::generateCronJobName($className, $method->getName());

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
        $prefix = (string) preg_replace('/^Mage_/', '', $className);
        $prefix = (string) preg_replace('/_Model_/', '_', $prefix);
        $prefix = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $prefix));
        $method = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $methodName));
        return $prefix . '_' . $method;
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
        // Mage_Wishlist_Model_Observer → Mage_Wishlist
        // Mage_CatalogInventory_Model_Observer → Mage_CatalogInventory
        if (preg_match('/^(Mage_[A-Za-z]+)_/', $className, $matches) === 1) {
            return $matches[1];
        }
        // Maho\SomeModule\... → derive from namespace
        if (preg_match('/^(Maho\\\\[A-Za-z]+)\\\\/', $className, $matches) === 1) {
            return $matches[1];
        }
        return $className;
    }

    private static function writeOutput(string $outputDir): void
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $content = '<?php return ' . var_export(self::$data, true) . ";\n";
        file_put_contents($outputDir . '/attributes.php', $content);
    }
}
