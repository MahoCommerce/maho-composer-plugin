<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Composer\IO\IOInterface;
use Maho\Config\CronJob;
use Maho\Config\Observer;
use Maho\Config\Route;
use ReflectionClass;
use ReflectionMethod;

final class AttributeCompiler
{
    /**
     * Map of class prefix → model group alias built from config.xml files.
     * e.g. 'Mage_Newsletter_Model' => 'newsletter'
     *
     * @var array<string, string>
     */
    private static array $classAliasMap = [];

    /**
     * @var array{
     *     observers: array<string, array<string, list<array{name: string, module: string, class: string, alias: string, method: string, type: string, args: array<string, mixed>}>>>,
     *     crontab: array<string, array{alias: string, method: string, schedule: ?string, config_path: ?string}>,
     *     routes: array<string, array{path: string, class: string, action: string, methods: list<string>, defaults: array<string, mixed>, requirements: array<string, string>, area: string, module: string, controllerName: string}>,
     *     replaces?: array<string, array<string, list<array{target: string}>>>
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
            'observers' => [],
            'crontab' => [],
            'routes' => [],
        ];

        $replaces = [];

        self::buildClassAliasMap($io);

        foreach (self::scanClasses() as $className => $filePath) {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                continue;
            }

            if (strpos($contents, 'Maho\Config\Observer') === false
                && strpos($contents, 'Maho\Config\CronJob') === false
                && strpos($contents, 'Maho\Config\Route') === false
            ) {
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
                self::processRouteAttributes($method, $className, $io);
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

            $alias = self::resolveClassAlias($className) ?? $className;
            $name = $observer->id ?? $alias . '::' . $method->getName();
            $areas = array_map('trim', explode(',', $observer->area));
            $event = strtolower($observer->event);

            $entry = [
                'name' => $name,
                'module' => self::extractModuleName($className),
                'class' => $className,
                'alias' => $alias,
                'method' => $method->getName(),
                'type' => $observer->type,
            ];

            foreach ($areas as $area) {
                self::$data['observers'][$area] ??= [];
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

            $name = $cronJob->id;

            if (isset(self::$data['crontab'][$name])) {
                $io->writeError(sprintf(
                    '  <warning>CronJob name "%s" on %s::%s overwrites %s::%s</warning>',
                    $name,
                    $className,
                    $method->getName(),
                    self::$data['crontab'][$name]['alias'],
                    self::$data['crontab'][$name]['method'],
                ));
            }

            self::$data['crontab'][$name] = [
                'alias' => self::resolveClassAlias($className) ?? $className,
                'method' => $method->getName(),
                'schedule' => $cronJob->schedule,
                'config_path' => $cronJob->configPath,
            ];
        }
    }

    private static function processRouteAttributes(
        ReflectionMethod $method,
        string $className,
        IOInterface $io,
    ): void {
        $attributes = $method->getAttributes(Route::class);
        foreach ($attributes as $attribute) {
            try {
                $route = $attribute->newInstance();
            } catch (\Throwable $e) {
                $io->writeError(sprintf(
                    '  <warning>Skipping Route attribute on %s::%s: %s</warning>',
                    $className,
                    $method->getName(),
                    $e->getMessage(),
                ));
                continue;
            }

            $name = $route->name ?? self::generateRouteName($className, $method->getName());
            $area = $route->area ?? self::detectControllerArea($className);

            if (isset(self::$data['routes'][$name])) {
                $io->writeError(sprintf(
                    '  <warning>Duplicate route name "%s" on %s::%s overwrites %s::%s</warning>',
                    $name,
                    $className,
                    $method->getName(),
                    self::$data['routes'][$name]['class'],
                    self::$data['routes'][$name]['action'],
                ));
            }

            if ($area === 'adminhtml') {
                $module = self::extractAdminModuleName($className);
                $controllerName = self::extractAdminControllerName($className);
            } else {
                $module = self::extractModuleName($className);
                $controllerName = self::extractControllerName($className);
            }

            self::$data['routes'][$name] = [
                'path' => $route->path,
                'class' => $className,
                'action' => $method->getName(),
                'methods' => $route->methods,
                'defaults' => $route->defaults,
                'requirements' => $route->requirements,
                'area' => $area,
                'module' => $module,
                'controllerName' => $controllerName,
            ];
        }
    }

    /**
     * Generate a route name from class and method names.
     *
     * e.g. 'Mage_Contacts_IndexController::postAction' → 'mage.contacts.index.post'
     */
    private static function generateRouteName(string $className, string $methodName): string
    {
        $action = (string) preg_replace('/Action$/', '', $methodName);

        $classKey = str_replace(['\\', '_'], '.', $className);
        $classKey = strtolower((string) preg_replace('/Controller$/', '', $classKey));

        return $classKey . '.' . strtolower($action);
    }

    /**
     * Detect the area from the controller's class hierarchy.
     */
    private static function detectControllerArea(string $className): string
    {
        if (!class_exists($className)) {
            return 'frontend';
        }

        $ref = new ReflectionClass($className);
        while ($ref !== false) {
            $name = $ref->getName();
            if ($name === 'Mage_Adminhtml_Controller_Action'
                || $name === 'Maho\\Controller\\AdminAction'
            ) {
                return 'adminhtml';
            }
            if ($name === 'Mage_Install_Controller_Action') {
                return 'install';
            }
            $ref = $ref->getParentClass();
        }

        return 'frontend';
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
                        static fn (array $observer): bool => !self::observerMatchesTarget($observer, $target),
                    ),
                );
                self::$data['observers'][$area][$event] = $observers;
                $resolved = count($observers) < $countBefore;
            }

            if (!$resolved) {
                $unresolvedReplaces[] = $replace;
            }
        }

        // Store unresolved replaces indexed by area/event for runtime resolution against XML observers
        if ($unresolvedReplaces !== []) {
            $indexed = [];
            foreach ($unresolvedReplaces as $replace) {
                $indexed[$replace['area']][$replace['event']][] = ['target' => $replace['target']];
            }
            self::$data['replaces'] = $indexed;
        }
    }

    /**
     * Check if an observer matches a replaces target.
     *
     * Supports matching by:
     * - Exact name (e.g. 'my_observer')
     * - Alias format (e.g. 'catalog/observer::myMethod')
     * - Class name format (e.g. 'Mage_Catalog_Model_Observer::myMethod')
     *
     * @param array{name: string, module: string, class: string, alias: string, method: string, type: string, args: array<string, mixed>} $observer
     */
    private static function observerMatchesTarget(array $observer, string $target): bool
    {
        if ($observer['name'] === $target) {
            return true;
        }

        if (str_contains($target, '::')) {
            $aliasBasedName = $observer['alias'] . '::' . $observer['method'];
            if ($aliasBasedName === $target) {
                return true;
            }

            $classBasedName = $observer['class'] . '::' . $observer['method'];
            if ($classBasedName === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a map of class prefixes to group aliases by parsing config.xml files.
     * Reads <models>, <helpers>, and <blocks> groups from each module's config.
     * e.g. 'Mage_Newsletter_Model' => 'newsletter' from <models><newsletter><class>Mage_Newsletter_Model</class>
     */
    private static function buildClassAliasMap(IOInterface $io): void
    {
        self::$classAliasMap = [];

        foreach (AutoloadRuntime::globPackages('/app/code/*/*/etc/config.xml') as $configFile) {
            $xml = @simplexml_load_file($configFile);
            if ($xml === false) {
                $io->writeError(sprintf('  <warning>Failed to parse %s, skipping alias resolution for this module</warning>', $configFile));
                continue;
            }

            foreach (['models', 'helpers', 'blocks'] as $groupType) {
                foreach ($xml->global->{$groupType}->children() as $groupName => $groupConfig) {
                    $classPrefix = (string) $groupConfig->class;
                    if ($classPrefix !== '') {
                        self::$classAliasMap[$classPrefix] = strtolower($groupName);
                    }
                }
            }
        }
    }

    /**
     * Resolve a FQCN to a Maho class alias (e.g. 'Mage_Newsletter_Model_Observer' => 'newsletter/observer').
     * Returns null if no matching alias is found.
     */
    private static function resolveClassAlias(string $className): ?string
    {
        foreach (self::$classAliasMap as $prefix => $group) {
            if (str_starts_with($className, $prefix . '_')) {
                $suffix = substr($className, strlen($prefix) + 1);
                $parts = explode('_', $suffix);
                $alias = implode('_', array_map('lcfirst', $parts));
                return $group . '/' . $alias;
            }
        }
        return null;
    }

    /**
     * Extract the controller short name from a controller class name.
     *
     * e.g. 'Mage_Checkout_CartController' → 'cart'
     *      'Mage_Paygate_Authorizenet_PaymentController' → 'authorizenet_payment'
     */
    private static function extractControllerName(string $className): string
    {
        // Remove 'Controller' suffix
        $name = (string) preg_replace('/Controller$/', '', $className);

        // Get everything after the module prefix (first two segments)
        $parts = explode('_', $name);
        if (count($parts) > 2) {
            $controllerParts = array_slice($parts, 2);
            return strtolower(implode('_', $controllerParts));
        }

        return strtolower($parts[count($parts) - 1] ?? '');
    }

    /**
     * Extract the routing module name for admin controllers.
     *
     * For Mage_Adminhtml controllers (e.g. Mage_Adminhtml_Catalog_ProductController),
     * the module is the first two segments: 'Mage_Adminhtml'.
     *
     * For non-Adminhtml modules (e.g. Mage_Bundle_Adminhtml_BundleController),
     * the module includes the Adminhtml segment: 'Mage_Bundle_Adminhtml'.
     */
    private static function extractAdminModuleName(string $className): string
    {
        $parts = explode('_', (string) preg_replace('/Controller$/', '', $className));

        // If the third segment is 'Adminhtml', the routing module includes it
        // e.g. Mage_Bundle_Adminhtml_Bundle → Mage_Bundle_Adminhtml
        if (count($parts) > 2 && $parts[2] === 'Adminhtml' && $parts[1] !== 'Adminhtml') {
            return $parts[0] . '_' . $parts[1] . '_' . $parts[2];
        }

        // Standard Adminhtml module: Mage_Adminhtml_Catalog_Product → Mage_Adminhtml
        if (count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1];
        }

        return $className;
    }

    /**
     * Extract the controller short name for admin controllers.
     *
     * For Mage_Adminhtml controllers (e.g. Mage_Adminhtml_Catalog_ProductController),
     * everything after the module prefix: 'catalog_product'.
     *
     * For non-Adminhtml modules (e.g. Mage_Bundle_Adminhtml_BundleController),
     * everything after the Adminhtml segment: 'bundle'.
     */
    private static function extractAdminControllerName(string $className): string
    {
        $name = (string) preg_replace('/Controller$/', '', $className);
        $parts = explode('_', $name);

        // Non-Adminhtml module: skip first 3 segments (Vendor_Module_Adminhtml)
        if (count($parts) > 3 && $parts[2] === 'Adminhtml' && $parts[1] !== 'Adminhtml') {
            return strtolower(implode('_', array_slice($parts, 3)));
        }

        // Standard Adminhtml module: skip first 2 segments (Mage_Adminhtml)
        if (count($parts) > 2) {
            return strtolower(implode('_', array_slice($parts, 2)));
        }

        return strtolower($parts[count($parts) - 1] ?? '');
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
        $content = '<?php return ' . var_export(self::$data, true) . ";\n";
        if (file_put_contents($outputDir . '/maho_attributes.php', $content) === false) {
            $io->writeError(sprintf('  <error>Failed to write %s/maho_attributes.php</error>', $outputDir));
        }
    }
}
