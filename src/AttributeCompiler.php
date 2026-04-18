<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use Composer\ClassMapGenerator\ClassMapGenerator;
use Composer\IO\IOInterface;
use Maho\Config\CronJob;
use Maho\Config\Observer;
use Maho\Config\Route;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Generator\Dumper\CompiledUrlGeneratorDumper;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\Route as SymfonyRoute;
use Symfony\Component\Routing\RouteCollection;

final class AttributeCompiler
{
    /**
     * Set of active module names (e.g. 'Mage_Core' => true), built from app/etc/modules/*.xml.
     * Null means no module XMLs were found → treat all modules as active (no filtering).
     *
     * @var array<string, true>|null
     */
    private static ?array $activeModules = null;

    /**
     * Map of class prefix → model group alias built from config.xml files.
     * e.g. 'Mage_Newsletter_Model' => 'newsletter'
     *
     * @var array<string, string>
     */
    private static array $classAliasMap = [];


    /**
     * Sentinel key for admin area in reverseLookup / controllerLookup.
     * Admin frontName is runtime-configurable (use_custom_admin_path), so routes are
     * keyed by this sentinel in compiled lookup maps and translated at dispatch time.
     */
    public const ADMIN_SENTINEL = '__admin__';

    /**
     * Sentinel key for install area in reverseLookup / controllerLookup.
     */
    public const INSTALL_SENTINEL = '__install__';

    /**
     * @var array{
     *     observers: array<string, array<string, list<array{name: string, module: string, class: string, alias: string, method: string, type: string}>>>,
     *     crontab: array<string, array{module: string, alias: string, method: string, schedule: ?string, config_path: ?string}>,
     *     routes: array<string, array{path: string, class: string, action: string, methods: list<string>, defaults: array<string, mixed>, requirements: array<string, string>, area: string, module: string, controllerName: string, pathVariables: list<string>}>,
     *     replaces?: array<string, array<string, list<array{target: string}>>>,
     *     reverseLookup: array<string, string>,
     *     controllerLookup: array<string, string>
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
            'reverseLookup' => [],
            'controllerLookup' => [],
        ];

        $replaces = [];

        self::buildActiveModules($io);
        self::buildConfigMaps($io);

        $scannedClasses = self::scanClasses();

        // Register a temporary classmap autoloader so that class_exists() and
        // ReflectionClass work for all scanned classes, including PSR-4 controllers
        // that the minimal autoloader in AutoloadPlugin cannot resolve.
        $classMapAutoloader = static function (string $class) use ($scannedClasses): void {
            if (isset($scannedClasses[$class])) {
                require_once $scannedClasses[$class];
            }
        };
        spl_autoload_register($classMapAutoloader);

        try {
            foreach ($scannedClasses as $className => $filePath) {
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
        } finally {
            spl_autoload_unregister($classMapAutoloader);
        }

        self::applyReplaces($replaces);
        self::buildReverseLookup($io);
        self::writeOutput($outputDir, $io);
        self::dumpRoutingFiles($outputDir, $io);
    }

    /**
     * Scan module directories for PHP classes using Composer's ClassMapGenerator.
     *
     * Scans both legacy code pools (app/code/{pool}/{Namespace}/{Module}/) and
     * PSR-4 sources from installed maho-source / maho-module / magento-module packages.
     * The root package is excluded from the PSR-4 scan to avoid traversing vendor/.
     *
     * Later packages overwrite earlier ones so that local code pool
     * overrides take precedence over core/community classes.
     *
     * Modules disabled in app/etc/modules/*.xml (or with disabled dependencies) are skipped.
     *
     * @return array<class-string, string>
     */
    private static function scanClasses(): array
    {
        $classes = [];

        // Legacy code pool: app/code/{pool}/{Namespace}/{Module}/
        foreach (AutoloadRuntime::globPackages('/app/code/*/*/*', GLOB_ONLYDIR) as $moduleDir) {
            if (self::$activeModules !== null) {
                $segments = array_slice(explode('/', $moduleDir), -2);
                if (!isset(self::$activeModules[implode('_', $segments)])) {
                    continue;
                }
            }
            foreach (ClassMapGenerator::createMap($moduleDir) as $class => $file) {
                $classes[$class] = $file;
            }
        }

        // PSR-4 classes: scan each installed (non-root) maho/magento package in full
        $packages = AutoloadRuntime::getInstalledPackages();
        unset($packages['root']);
        foreach ($packages as $info) {
            foreach (ClassMapGenerator::createMap($info['path']) as $class => $file) {
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
                'module' => self::extractModuleName($className),
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
            $area = $route->area ?? self::detectControllerArea($className, $io);

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

            // Admin paths compile to a placeholder so the runtime admin frontName
            // (use_custom_admin_path) can be injected without re-dumping the matcher.
            $path = $route->path;
            if ($area === 'adminhtml') {
                $path = (string) preg_replace('#^/admin(/|$)#', '/{_adminFrontName}$1', $path);
            }

            preg_match_all('/\{(\w+)\}/', $path, $pathVarMatches);

            self::$data['routes'][$name] = [
                'path' => $path,
                'class' => $className,
                'action' => $method->getName(),
                'methods' => $route->methods,
                'defaults' => $route->defaults,
                'requirements' => $route->requirements,
                'area' => $area,
                'module' => self::extractModuleName($className),
                'controllerName' => self::extractControllerName($className),
                'pathVariables' => $pathVarMatches[1],
            ];
        }
    }

    /**
     * Generate a route name from class and method names.
     *
     * e.g. 'Mage_Contacts_IndexController::postAction' → 'mage.contacts.index.post'
     *      'Maho\Contacts\Controller\IndexController::postAction' → 'maho.contacts.index.post'
     */
    private static function generateRouteName(string $className, string $methodName): string
    {
        $action = (string) preg_replace('/Action$/', '', $methodName);

        if (str_contains($className, '\\')) {
            // PSR-4: strip 'Controller' class suffix and 'Controller' namespace segment
            $name = (string) preg_replace('/Controller$/', '', $className);
            $parts = array_filter(
                explode('\\', $name),
                static fn (string $p): bool => strtolower($p) !== 'controller',
            );
            $classKey = strtolower(implode('.', $parts));
        } else {
            // Legacy underscore-style: Mage_Contacts_IndexController → mage.contacts.index
            $classKey = strtolower((string) preg_replace('/Controller$/', '', str_replace('_', '.', $className)));
        }

        return $classKey . '.' . strtolower($action);
    }

    /**
     * Detect the area from the controller's class hierarchy.
     */
    private static function detectControllerArea(string $className, IOInterface $io): string
    {
        if (!class_exists($className)) {
            $io->writeError(sprintf(
                '  <warning>Cannot load class %s for area detection, defaulting to frontend</warning>',
                $className,
            ));
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
            if ($name === 'Mage_Install_Controller_Action'
                || $name === 'Maho\\Controller\\InstallAction'
            ) {
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
     * @param array{name: string, module: string, class: string, alias: string, method: string, type: string} $observer
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
     * Parse all app/etc/modules/*.xml files to determine which modules are active.
     *
     * A module is considered effectively active only if:
     * - Its <active> flag is not false/0
     * - All modules listed in its <depends> block are also effectively active
     *
     * Sets self::$activeModules to a name→true map of active modules,
     * or leaves it null when no module XMLs are found (treat all modules as active).
     */
    private static function buildActiveModules(IOInterface $io): void
    {
        self::$activeModules = null;

        $moduleXmls = AutoloadRuntime::globPackages('/app/etc/modules/*.xml');
        if ($moduleXmls === []) {
            return;
        }

        /** @var array<string, array{active: bool, depends: list<string>}> $modules */
        $modules = [];

        // Process in order: later declarations (root package last) override earlier ones
        foreach ($moduleXmls as $xmlFile) {
            $xml = @simplexml_load_file($xmlFile);
            if ($xml === false) {
                $io->writeError(sprintf('  <warning>Failed to parse %s, skipping</warning>', $xmlFile));
                continue;
            }
            foreach ($xml->modules->children() ?? [] as $moduleName => $moduleConfig) {
                $activeStr = strtolower(trim((string) ($moduleConfig->active ?? 'true')));
                $active = ($activeStr !== 'false' && $activeStr !== '0');
                $depends = [];
                if (isset($moduleConfig->depends)) {
                    foreach ($moduleConfig->depends->children() ?? [] as $depName => $_) {
                        $depends[] = $depName;
                    }
                }
                $modules[$moduleName] = ['active' => $active, 'depends' => $depends];
            }
        }

        if ($modules === []) {
            return;
        }

        /** @var array<string, bool> $resolved */
        $resolved = [];

        $resolve = static function (string $name) use (&$modules, &$resolved, &$resolve): bool {
            if (array_key_exists($name, $resolved)) {
                return $resolved[$name];
            }
            if (!isset($modules[$name])) {
                return $resolved[$name] = true; // Unknown dep → assume active
            }
            if (!$modules[$name]['active']) {
                return $resolved[$name] = false;
            }
            $resolved[$name] = true; // Guard against circular deps
            foreach ($modules[$name]['depends'] as $dep) {
                if (!$resolve($dep)) {
                    return $resolved[$name] = false;
                }
            }
            return $resolved[$name];
        };

        foreach (array_keys($modules) as $name) {
            $resolve($name);
        }

        /** @var array<string, true> $activeMap */
        $activeMap = [];
        foreach ($resolved as $name => $active) {
            if ($active) {
                $activeMap[$name] = true;
            }
        }
        self::$activeModules = $activeMap;
    }

    /**
     * Parse <global><models|helpers|blocks><group><class> across all module config.xml files
     * to build the class-alias map (e.g. 'Mage_Newsletter_Model' => 'newsletter').
     */
    private static function buildConfigMaps(IOInterface $io): void
    {
        self::$classAliasMap = [];

        foreach (AutoloadRuntime::globPackages('/app/code/*/*/*/etc/config.xml') as $configFile) {
            if (self::$activeModules !== null) {
                // Path: .../app/code/{pool}/{Namespace}/{Module}/etc/config.xml
                $segments = array_slice(explode('/', $configFile), -4, 2);
                if (!isset(self::$activeModules[implode('_', $segments)])) {
                    continue;
                }
            }

            $xml = @simplexml_load_file($configFile);
            if ($xml === false) {
                $io->writeError(sprintf('  <warning>Failed to parse %s, skipping config maps for this module</warning>', $configFile));
                continue;
            }

            foreach (['models', 'helpers', 'blocks'] as $groupType) {
                foreach ($xml->global->{$groupType}->children() ?? [] as $groupName => $groupConfig) {
                    $classPrefix = (string) $groupConfig->class;
                    if ($classPrefix !== '') {
                        self::$classAliasMap[$classPrefix] = strtolower($groupName);
                    }
                }
            }
        }
    }

    /**
     * Build reverse-lookup maps keyed by frontName.
     *
     * - `reverseLookup`: frontName/controller/action → routeName (used by URL generation)
     * - `controllerLookup`: frontName/controller → module class prefix (used by forward dispatch)
     *
     * Admin and install routes are keyed by a sentinel rather than the config.xml frontName,
     * because the runtime admin frontName is configurable via `use_custom_admin_path`.
     * The runtime translates the incoming frontName to the sentinel before lookup.
     *
     * For PSR-4 modules, extractModuleName() returns 'Vendor\Module' while config.xml <args><module>
     * typically uses 'Vendor_Module'. Both formats are tried so either convention works.
     */
    private static function buildReverseLookup(IOInterface $io): void
    {
        $reverseLookup = [];
        $controllerLookup = [];

        foreach (self::$data['routes'] as $routeName => $route) {
            // Frontend frontName is the first segment of the route path (e.g. '/catalog/...').
            // Admin and install use sentinels because their runtime frontName can differ
            // from the compile-time one (use_custom_admin_path).
            $frontName = match ($route['area']) {
                'adminhtml' => self::ADMIN_SENTINEL,
                'install' => self::INSTALL_SENTINEL,
                default => explode('/', ltrim($route['path'], '/'))[0],
            };

            if ($frontName === '') {
                if ($io->isVerbose()) {
                    $io->writeError(sprintf(
                        '  <warning>No frontName derivable from path "%s", skipping reverse lookup for route "%s"</warning>',
                        $route['path'],
                        $routeName,
                    ));
                }
                continue;
            }

            $action = strtolower((string) preg_replace('/Action$/', '', $route['action']));
            $reverseLookup[$frontName . '/' . $route['controllerName'] . '/' . $action] = $routeName;
            $controllerLookup[$frontName . '/' . $route['controllerName']] = $route['module'];
        }

        self::$data['reverseLookup'] = $reverseLookup;
        self::$data['controllerLookup'] = $controllerLookup;
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
     * Legacy underscore-style:
     *   'Mage_Checkout_CartController' → 'cart'
     *   'Mage_Paygate_Authorizenet_PaymentController' → 'authorizenet_payment'
     *   'Mage_Bundle_Adminhtml_Bundle_Product_EditController' → 'bundle_product_edit'
     *
     * PSR-4 style:
     *   'Maho\Checkout\Controller\CartController' → 'cart'
     *   'Maho\Bundle\Controller\Adminhtml\Product\EditController' → 'product_edit'
     */
    private static function extractControllerName(string $className): string
    {
        // Remove 'Controller' suffix
        $name = (string) preg_replace('/Controller$/', '', $className);

        if (str_contains($name, '\\')) {
            // PSR-4: take all segments after 'Controller' namespace segment (skip vendor+module prefix too)
            $parts = explode('\\', $name);
            $controllerNsIdx = null;
            foreach ($parts as $i => $part) {
                if (strtolower($part) === 'controller') {
                    $controllerNsIdx = $i;
                    break;
                }
            }
            if ($controllerNsIdx !== null) {
                $controllerParts = array_slice($parts, $controllerNsIdx + 1);
            } else {
                // No 'Controller' namespace segment — take everything after vendor+module (first two)
                $controllerParts = array_slice($parts, 2);
            }
            // Skip leading 'Adminhtml'/'Install' organizational segments
            if (isset($controllerParts[0])
                && in_array(strtolower($controllerParts[0]), ['adminhtml', 'install'], true)
            ) {
                array_shift($controllerParts);
            }
            return strtolower(implode('_', $controllerParts));
        }

        // Legacy underscore-style: get everything after the module prefix (first two segments)
        $parts = explode('_', $name);
        if (count($parts) > 2) {
            $controllerParts = array_slice($parts, 2);

            // Sub-module admin controllers live in a controllers/Adminhtml/ subdirectory.
            // The 'Adminhtml' segment is organizational (not part of the URL controller name)
            // and should be skipped — unless the module itself is named 'Adminhtml'
            // (e.g. Mage_Adminhtml_Catalog_ProductController → 'catalog_product').
            if (
                strtolower($controllerParts[0]) === 'adminhtml'
                && strtolower($parts[1]) !== 'adminhtml'
            ) {
                array_shift($controllerParts);
            }

            return strtolower(implode('_', $controllerParts));
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

    /**
     * Build a Symfony RouteCollection from compiled routes and dump pre-compiled
     * matcher/generator arrays. The dumped files are plain PHP arrays, fully opcached,
     * so URL matching and generation do no RouteCollection construction at runtime.
     *
     * Admin routes carry an `{_adminFrontName}` placeholder with a `/{_catchall}`
     * suffix for the key/value param convention (e.g. /admin/foo/bar/id/5/store/1).
     */
    private static function dumpRoutingFiles(string $outputDir, IOInterface $io): void
    {
        $collection = new RouteCollection();

        foreach (self::$data['routes'] as $name => $route) {
            $path = $route['path'];
            $requirements = $route['requirements'];

            // Frontend frontName is the first segment of the path; admin/install carry it
            // via the `{_adminFrontName}` placeholder or the literal `install` segment.
            $firstSegment = explode('/', ltrim($route['path'], '/'))[0];

            $defaults = array_merge($route['defaults'], [
                '_maho_controller' => $route['class'],
                '_maho_action' => $route['action'],
                '_maho_area' => $route['area'],
                '_maho_module' => $route['module'],
                '_maho_controller_name' => $route['controllerName'],
                '_maho_front_name' => $firstSegment,
            ]);

            if ($route['area'] === 'adminhtml') {
                $path = rtrim($path, '/') . '/{_catchall}';
                $defaults['_catchall'] = '';
                $requirements['_catchall'] = '.*';
            }

            $symfonyRoute = new SymfonyRoute($path, $defaults, $requirements);
            if ($route['methods'] !== []) {
                $symfonyRoute->setMethods($route['methods']);
            }
            $collection->add($name, $symfonyRoute);
        }

        $matcherDumper = new CompiledUrlMatcherDumper($collection);
        if (file_put_contents($outputDir . '/maho_url_matcher.php', $matcherDumper->dump()) === false) {
            $io->writeError(sprintf('  <error>Failed to write %s/maho_url_matcher.php</error>', $outputDir));
        }

        $generatorDumper = new CompiledUrlGeneratorDumper($collection);
        if (file_put_contents($outputDir . '/maho_url_generator.php', $generatorDumper->dump()) === false) {
            $io->writeError(sprintf('  <error>Failed to write %s/maho_url_generator.php</error>', $outputDir));
        }
    }
}
