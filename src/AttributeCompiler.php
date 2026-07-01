<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

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
     * Tracks whether buildActiveModules() has run. Distinct from $activeModules being null
     * (which legitimately means "no XMLs found, treat all as active").
     */
    private static bool $activeModulesBuilt = false;

    /**
     * Memoised result of scanClasses(). Process-scoped — composer dump-autoload runs in
     * short-lived processes so a per-process cache is sufficient.
     *
     * @var array<class-string, string>|null
     */
    private static ?array $scannedClassesCache = null;

    /**
     * Map of class prefix → model group alias built from config.xml files.
     * e.g. 'Mage_Newsletter_Model' => 'newsletter'
     *
     * @var array<string, string>
     */
    private static array $classAliasMap = [];

    /**
     * Map of route-owning controller class → the most-derived subclass that overrides it.
     *
     * Populated by collectControllerOverrides(): a controller subclass that extends a
     * route-owning controller but declares no `#[Route]` of its own is an implicit override.
     * The compiler points the relevant `controllerLookup` entry at the override instead of
     * the base, so overriding a core controller needs no XML and no extra attribute — just
     * the subclass. Only entries where an override actually exists are stored.
     *
     * @var array<class-string, class-string>
     */
    private static array $controllerOverrides = [];

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
     * Compile PHP attributes into a cached array file (composer-time entry point).
     *
     * @param string $outputDir Directory where attributes.php will be written
     */
    public static function compile(string $outputDir, IOInterface $io): void
    {
        self::doCompile($outputDir, self::ioToLog($io));
    }

    /**
     * Compile PHP attributes at runtime (no composer/composer dependency).
     *
     * The optional logger receives `(string $level, string $message)` for any
     * warnings/errors emitted during compilation. Pass null for silent operation.
     *
     * @param \Closure(string, string): void|null $log
     */
    public static function compileRuntime(string $outputDir, ?\Closure $log = null): void
    {
        self::resetState();
        self::doCompile($outputDir, $log);
    }

    private static function doCompile(string $outputDir, ?\Closure $log): void
    {
        self::$data = [
            'observers' => [],
            'crontab' => [],
            'routes' => [],
            'reverseLookup' => [],
            'controllerLookup' => [],
        ];
        self::$controllerOverrides = [];

        $replaces = [];

        self::buildActiveModules($log);
        self::buildConfigMaps($log);

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

                    self::processObserverAttributes($method, $className, $replaces, $log);
                    self::processCronJobAttributes($method, $className, $log);
                    self::processRouteAttributes($method, $className, $log);
                }
            }

            // Detect implicit controller overrides while the classmap autoloader is still
            // registered — this reflects subclasses that the main loop skipped (they declare
            // no Maho\Config\* attribute, so the strpos pre-filter above excludes them).
            self::collectControllerOverrides($scannedClasses, $log);
        } finally {
            spl_autoload_unregister($classMapAutoloader);
        }

        self::applyReplaces($replaces);
        self::buildReverseLookup($log);
        self::writeOutput($outputDir, $log);
        self::dumpRoutingFiles($outputDir, $log);
    }

    /**
     * Reset process-scoped caches so a runtime recompile picks up changes.
     */
    private static function resetState(): void
    {
        self::$activeModules = null;
        self::$activeModulesBuilt = false;
        self::$scannedClassesCache = null;
        self::$classAliasMap = [];
        self::$controllerOverrides = [];
    }

    /**
     * Adapter: turn an IOInterface (composer-time) into the closure logger
     * used by the rest of the compiler.
     *
     * @return \Closure(string, string): void
     */
    private static function ioToLog(IOInterface $io): \Closure
    {
        return static function (string $level, string $message) use ($io): void {
            // Info-level messages are debug noise at composer-time — only emit when verbose.
            if ($level === 'info' && !$io->isVerbose()) {
                return;
            }
            $tag = match ($level) {
                'error', 'warning' => $level,
                default => 'info',
            };
            $io->writeError(sprintf('  <%s>%s</%s>', $tag, $message, $tag));
        };
    }

    /**
     * Emit a formatted log message via the optional logger, if any.
     */
    private static function logf(?\Closure $log, string $level, string $format, int|float|string|bool|null ...$args): void
    {
        if ($log !== null) {
            $log($level, sprintf($format, ...$args));
        }
    }

    /**
     * Token-based replacement for Composer\ClassMapGenerator. Returns
     * a class-string => file-path map for every PHP class/interface/trait/enum
     * declared under $dir. Skips anonymous classes.
     *
     * @return array<class-string, string>
     */
    private static function createClassMap(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $classes = [];
        $directoryIter = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        // The package's own top-level tests/ dir: skipped because test fixtures (controllers,
        // observers) must never leak into compiled routes/observers/overrides. This bites in a
        // dev checkout, where the maho-source package path is the whole repo tree (tests/ and
        // all). Anchored to the package root — not a name match at any depth — so a directory
        // deeper in the tree that happens to be named "tests" is left alone.
        $rootTestsDir = rtrim($dir, '/') . '/tests';
        // Skip vendor/, node_modules/, and dotdirs (.git, .idea, etc.) too — these never
        // contain compilable Maho classes, and recursing into vendor/ from the project root
        // tries to autoload the plugin's own composer-plugin classes.
        $filtered = new \RecursiveCallbackFilterIterator(
            $directoryIter,
            static function (\SplFileInfo $current) use ($rootTestsDir): bool {
                if (!$current->isDir()) {
                    return true;
                }
                if ($current->getPathname() === $rootTestsDir) {
                    return false;
                }
                $name = $current->getFilename();
                return $name !== 'vendor' && $name !== 'node_modules' && !str_starts_with($name, '.');
            },
        );
        $iter = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iter as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            // Cheap rejection: file must contain at least one declaration keyword.
            if (preg_match('/\b(?:class|interface|trait|enum)\b/i', $contents) !== 1) {
                continue;
            }

            foreach (self::extractClassesFromTokens($contents) as $class) {
                /** @var class-string $class */
                $classes[$class] = $file->getPathname();
            }
        }

        return $classes;
    }

    /**
     * Extract fully-qualified class/interface/trait/enum names declared in $contents.
     *
     * @return list<string>
     */
    private static function extractClassesFromTokens(string $contents): array
    {
        $tokens = @token_get_all($contents);
        $classes = [];
        $namespace = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            switch ($token[0]) {
                case T_NAMESPACE:
                    $ns = '';
                    for ($j = $i + 1; $j < $count; $j++) {
                        $t = $tokens[$j];
                        if (is_string($t) && ($t === ';' || $t === '{')) {
                            break;
                        }
                        if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                            $ns .= $t[1];
                        }
                    }
                    $namespace = $ns;
                    break;

                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                case T_ENUM:
                    // Skip Foo::class syntax.
                    $prev = $i > 0 ? $tokens[$i - 1] : null;
                    if (is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                        break;
                    }
                    // Find the next T_STRING (class name); abort on `(` (anonymous class) or `{` (no name).
                    for ($j = $i + 1; $j < $count; $j++) {
                        $t = $tokens[$j];
                        if (is_string($t) && ($t === '(' || $t === '{')) {
                            break;
                        }
                        if (is_array($t) && $t[0] === T_STRING) {
                            $classes[] = ($namespace !== '' ? $namespace . '\\' : '') . $t[1];
                            $i = $j;
                            break;
                        }
                    }
                    break;
            }
        }

        return $classes;
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
     * Result is memoised per process so sibling compilers (e.g. ApiPermissionCompiler)
     * can reuse the scan without paying its cost twice per `composer dump-autoload`.
     * The active-module filter is built lazily on first call when not already populated,
     * so this method can be invoked standalone without a prior compile() call.
     *
     * @return array<class-string, string>
     */
    public static function scanClasses(?\Closure $log = null): array
    {
        if (self::$scannedClassesCache !== null) {
            return self::$scannedClassesCache;
        }

        if (!self::$activeModulesBuilt) {
            self::buildActiveModules($log);
        }

        $classes = [];

        // Legacy code pool: app/code/{pool}/{Namespace}/{Module}/
        foreach (AutoloadRuntime::globPackages('/app/code/*/*/*', GLOB_ONLYDIR) as $moduleDir) {
            if (self::$activeModules !== null) {
                $segments = array_slice(explode('/', $moduleDir), -2);
                if (!isset(self::$activeModules[implode('_', $segments)])) {
                    continue;
                }
            }
            foreach (self::createClassMap($moduleDir) as $class => $file) {
                $classes[$class] = $file;
            }
        }

        // PSR-4 classes: scan each installed (non-root) maho/magento package in full
        $packages = AutoloadRuntime::getInstalledPackages();
        unset($packages['root']);
        foreach ($packages as $info) {
            foreach (self::createClassMap($info['path']) as $class => $file) {
                $classes[$class] = $file;
            }
        }

        return self::$scannedClassesCache = $classes;
    }

    /**
     * @param list<array{target: string, area: string, event: string}> $replaces
     */
    private static function processObserverAttributes(
        ReflectionMethod $method,
        string $className,
        array &$replaces,
        ?\Closure $log,
    ): void {
        $attributes = $method->getAttributes(Observer::class);
        foreach ($attributes as $attribute) {
            try {
                $observer = $attribute->newInstance();
            } catch (\Throwable $e) {
                self::logf(
                    $log,
                    'warning',
                    'Skipping Observer attribute on %s::%s: %s',
                    $className,
                    $method->getName(),
                    $e->getMessage(),
                );
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
                        self::logf(
                            $log,
                            'warning',
                            'Duplicate observer name "%s" on %s/%s',
                            $name,
                            $area,
                            $event,
                        );
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
        ?\Closure $log,
    ): void {
        $attributes = $method->getAttributes(CronJob::class);
        foreach ($attributes as $attribute) {
            try {
                $cronJob = $attribute->newInstance();
            } catch (\Throwable $e) {
                self::logf(
                    $log,
                    'warning',
                    'Skipping CronJob attribute on %s::%s: %s',
                    $className,
                    $method->getName(),
                    $e->getMessage(),
                );
                continue;
            }

            if ($cronJob->schedule === null && $cronJob->configPath === null) {
                self::logf(
                    $log,
                    'warning',
                    'CronJob on %s::%s has neither schedule nor config_path, skipping',
                    $className,
                    $method->getName(),
                );
                continue;
            }

            $name = $cronJob->id;

            if (isset(self::$data['crontab'][$name])) {
                self::logf(
                    $log,
                    'warning',
                    'CronJob name "%s" on %s::%s overwrites %s::%s',
                    $name,
                    $className,
                    $method->getName(),
                    self::$data['crontab'][$name]['alias'],
                    self::$data['crontab'][$name]['method'],
                );
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
        ?\Closure $log,
    ): void {
        $attributes = $method->getAttributes(Route::class);
        foreach ($attributes as $attribute) {
            try {
                $route = $attribute->newInstance();
            } catch (\Throwable $e) {
                self::logf(
                    $log,
                    'warning',
                    'Skipping Route attribute on %s::%s: %s',
                    $className,
                    $method->getName(),
                    $e->getMessage(),
                );
                continue;
            }

            $name = $route->name ?? self::generateRouteName($className, $method->getName());
            $area = $route->area ?? self::detectControllerArea($className, $log);

            if (isset(self::$data['routes'][$name])) {
                self::logf(
                    $log,
                    'warning',
                    'Duplicate route name "%s" on %s::%s overwrites %s::%s',
                    $name,
                    $className,
                    $method->getName(),
                    self::$data['routes'][$name]['class'],
                    self::$data['routes'][$name]['action'],
                );
            }

            // Admin paths compile with a `{_adminFrontName}` placeholder so the runtime
            // admin frontName (use_custom_admin_path) can be injected per request.
            // - Path starting with /admin: substitute the prefix with the placeholder.
            // - Any other admin path: prepend the placeholder so third-party modules
            //   can declare routes like #[Route('/foo', area: 'adminhtml')] without
            //   needing to include the admin prefix themselves.
            $path = $route->path;
            if ($area === 'adminhtml') {
                if (preg_match('#^/admin(/|$)#', $path) === 1) {
                    $path = (string) preg_replace('#^/admin(/|$)#', '/{_adminFrontName}$1', $path);
                } else {
                    $path = '/{_adminFrontName}' . (str_starts_with($path, '/') ? '' : '/') . $path;
                }
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
    private static function detectControllerArea(string $className, ?\Closure $log): string
    {
        if (!class_exists($className)) {
            self::logf(
                $log,
                'warning',
                'Cannot load class %s for area detection, defaulting to frontend',
                $className,
            );
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
    private static function buildActiveModules(?\Closure $log): void
    {
        self::$activeModulesBuilt = true;
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
                self::logf($log, 'warning', 'Failed to parse %s, skipping', $xmlFile);
                continue;
            }
            foreach ($xml->modules?->children() ?? [] as $moduleName => $moduleConfig) {
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
    private static function buildConfigMaps(?\Closure $log): void
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
                self::logf($log, 'warning', 'Failed to parse %s, skipping config maps for this module', $configFile);
                continue;
            }

            foreach (['models', 'helpers', 'blocks'] as $groupType) {
                foreach ($xml->global?->{$groupType}?->children() ?? [] as $groupName => $groupConfig) {
                    $classPrefix = (string) $groupConfig->class;
                    if ($classPrefix !== '') {
                        self::$classAliasMap[$classPrefix] = strtolower($groupName);
                    }
                }
            }
        }
    }

    /**
     * Resolve the frontName key used in both the reverseLookup/controllerLookup maps
     * and the `_maho_front_name` route default. Admin/install use sentinels because
     * their runtime frontName can differ from the compile-time one (use_custom_admin_path).
     *
     * @param array{area: string, path: string, module: string, ...} $route
     */
    private static function resolveFrontNameKey(array $route): string
    {
        return match ($route['area']) {
            'adminhtml' => self::ADMIN_SENTINEL,
            'install' => self::INSTALL_SENTINEL,
            default => self::resolveFrontendFrontName($route),
        };
    }

    /**
     * Derive the frontName for a frontend route.
     *
     * The normal source of truth is the first segment of the URL path, which
     * by Magento 1 convention is the module's frontName (e.g. `/catalog/...`
     * → `catalog`). Routes that register the bare `/` path (typically a CMS
     * home page) have no front-name segment to extract, so we fall back to
     * the module's short name (e.g. `Mage_Cms` → `cms`, `Maho\News` → `news`)
     * which matches the Magento 1 naming convention.
     *
     * Without this fallback, `_maho_front_name` ends up empty for `/` routes.
     * At runtime the dispatcher then calls `$request->setRouteName('')`, and
     * `getFullActionName()` returns `_<controller>_<action>` (e.g.
     * `_index_index` for the CMS home page) instead of the expected
     * `cms_index_index`. Every layout XML block, widget instance, and
     * `<remove>` directive that targets the standard controller-action
     * handle on the home page is silently ignored.
     *
     * @param array{area: string, path: string, module: string, ...} $route
     */
    private static function resolveFrontendFrontName(array $route): string
    {
        $firstSegment = explode('/', ltrim($route['path'], '/'))[0];
        if ($firstSegment !== '') {
            return $firstSegment;
        }

        $module = $route['module'];
        if ($module === '') {
            return '';
        }
        $separator = str_contains($module, '\\') ? '\\' : '_';
        $tail = (string) strrchr($module, $separator);
        return strtolower($tail === '' ? $module : ltrim($tail, $separator));
    }

    /**
     * Detect implicit controller overrides and record the winner per route-owning base.
     *
     * A controller override is a subclass of a route-owning controller (one that declares
     * `#[Route]`) which itself declares no new route — it only reimplements inherited actions.
     * Such a subclass is registered as an override of its nearest route-owning ancestor, so
     * the compiled `controllerLookup` dispatches to it instead of the base. This replaces the
     * legacy `<routers><args><modules>` XML chain for the attribute-routed case: a module
     * overrides a core controller simply by subclassing it.
     *
     * Cross-module precedence is resolved structurally. When several subclasses target the
     * same base they normally form a single inheritance chain (B extends A extends Core), and
     * the most-derived class wins unambiguously. The only genuine conflict is two *sibling*
     * subclasses extending the base independently; that is reported as an error and resolved
     * deterministically by module load order (later-scanned wins — local/community over core).
     *
     * @param array<class-string, string> $scannedClasses class name → file path (in scan order)
     */
    private static function collectControllerOverrides(array $scannedClasses, ?\Closure $log): void
    {
        // Route-owning controllers — the bases an override can target.
        $baseClasses = [];
        foreach (self::$data['routes'] as $route) {
            $baseClasses[$route['class']] = true;
        }
        if ($baseClasses === []) {
            return;
        }

        // Group override candidates by the nearest route-owning ancestor they extend.
        // Iterate keys in scan order — module load order drives conflict tiebreaks below.
        $candidatesByBase = [];
        foreach (array_keys($scannedClasses) as $className) {
            // Controllers only; a route-owner is its own controller, never an override of one.
            if (!str_ends_with($className, 'Controller') || isset($baseClasses[$className])) {
                continue;
            }
            // Loading a subclass links its whole parent chain; a controller with a
            // missing/renamed parent throws here. Isolate it so one broken controller
            // can't abort the entire compile (matches the attribute paths' warn-and-skip).
            try {
                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new ReflectionClass($className);
                if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                    continue;
                }

                for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
                    if (isset($baseClasses[$parent->getName()])) {
                        $candidatesByBase[$parent->getName()][] = $className;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                self::logf($log, 'warning', 'Skipping controller override candidate %s: %s', $className, $e->getMessage());
            }
        }

        foreach ($candidatesByBase as $base => $candidates) {
            $winner = self::resolveMostDerived($base, $candidates, $log);
            if ($winner !== $base) {
                self::$controllerOverrides[$base] = $winner;
            }
        }
    }

    /**
     * Pick the most-derived controller among a base and its override candidates.
     *
     * The winner is the unique class no other candidate extends (the bottom of the chain).
     * If two or more candidates are mutually incomparable (sibling overrides), there is no
     * unique winner: emit an error and fall back to the last candidate in scan order, which
     * is module load order — local/community modules override core.
     *
     * @param class-string       $base       the route-owning base controller
     * @param list<class-string> $candidates subclasses overriding $base, in scan order
     * @return class-string
     */
    private static function resolveMostDerived(string $base, array $candidates, ?\Closure $log): string
    {
        $set = array_merge([$base], $candidates);

        // Maximal = nothing in the set extends it. The base always has a descendant here
        // (every candidate subclasses it), so the winner is always an actual override.
        $maximal = [];
        foreach ($set as $candidate) {
            $isMaximal = true;
            foreach ($set as $other) {
                if ($other !== $candidate && is_subclass_of($other, $candidate)) {
                    $isMaximal = false;
                    break;
                }
            }
            if ($isMaximal) {
                $maximal[] = $candidate;
            }
        }

        if (count($maximal) === 1) {
            return $maximal[0];
        }

        // Sibling conflict: deterministic fallback to the last maximal candidate in scan order.
        $winner = $maximal[0];
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $maximal, true)) {
                $winner = $candidate;
            }
        }

        self::logf(
            $log,
            'error',
            'Controller override conflict on %s: %s independently override it with no shared inheritance chain. Using "%s" (last in module load order); resolve by having one override extend the other.',
            $base,
            implode(', ', $maximal),
            $winner,
        );

        return $winner;
    }

    /**
     * Build reverse-lookup maps keyed by frontName.
     *
     * - `reverseLookup`: frontName/controller/action → routeName (used by URL generation)
     * - `controllerLookup`: frontName/controller → controller class FQCN (used by forward dispatch)
     *
     * When an action exposes several routes (URL aliases), the last-declared wins the
     * reverse entry, so getUrl() resolves deterministically to it.
     *
     * `controllerLookup` stores the full controller class because the runtime can't
     * unambiguously reconstruct it from the module name — third-party admin modules
     * have an `_Adminhtml_` infix in the class (e.g. `Maho_FeedManager_Adminhtml_…`),
     * while `Mage_Adminhtml`'s own controllers don't. Storing the class directly
     * makes the lookup authoritative and eliminates convention-based guessing.
     *
     * Admin and install routes are keyed by a sentinel rather than the config.xml frontName,
     * because the runtime admin frontName is configurable via `use_custom_admin_path`.
     * The runtime translates the incoming frontName to the sentinel before lookup.
     */
    private static function buildReverseLookup(?\Closure $log): void
    {
        $reverseLookup = [];
        $reverseTargets = [];
        $controllerLookup = [];

        foreach (self::$data['routes'] as $routeName => $route) {
            $frontName = self::resolveFrontNameKey($route);

            if ($frontName === '') {
                self::logf(
                    $log,
                    'info',
                    'No frontName derivable from path "%s", skipping reverse lookup for route "%s"',
                    $route['path'],
                    $routeName,
                );
                continue;
            }

            $action = strtolower((string) preg_replace('/Action$/', '', $route['action']));
            $reverseKey = $frontName . '/' . $route['controllerName'] . '/' . $action;
            // Point the lookup at the most-derived override when one exists, falling back
            // to the route-owning base class. The reverse lookup below keeps targeting the
            // base — URL generation is keyed on the route, not on which subclass handles it.
            $controllerLookup[$frontName . '/' . $route['controllerName']] =
                self::$controllerOverrides[$route['class']] ?? $route['class'];

            $target = $route['class'] . '::' . $action;
            // Several routes can share a reverse key when an action exposes URL
            // aliases (e.g. a controller stacking #[Route] paths). The last-declared
            // route is the canonical getUrl() target, so same-target aliases overwrite
            // silently; only a cross-target overwrite is a genuine ambiguity worth a warning.
            if (isset($reverseTargets[$reverseKey]) && $reverseTargets[$reverseKey] !== $target) {
                self::logf(
                    $log,
                    'warning',
                    'Reverse lookup collision on "%s": routes "%s" (%s) and "%s" (%s) resolve to different targets; getUrl() will use the last-declared "%s"',
                    $reverseKey,
                    $reverseLookup[$reverseKey],
                    $reverseTargets[$reverseKey],
                    $routeName,
                    $target,
                    $routeName,
                );
            }
            $reverseLookup[$reverseKey] = $routeName;
            $reverseTargets[$reverseKey] = $target;
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

    private static function writeOutput(string $outputDir, ?\Closure $log): void
    {
        $content = '<?php return ' . var_export(self::$data, true) . ";\n";
        if (!self::atomicWrite($outputDir . '/maho_attributes.php', $content)) {
            self::logf($log, 'error', 'Failed to write %s/maho_attributes.php', $outputDir);
        }
    }

    /**
     * Write content to $path atomically: write to a unique temp file in the same
     * directory, then rename(). rename() is atomic on the same filesystem, so
     * concurrent readers either see the old file or the new one — never a torn write.
     */
    public static function atomicWrite(string $path, string $content): bool
    {
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Build a Symfony RouteCollection from compiled routes and dump pre-compiled
     * matcher/generator arrays. The dumped files are plain PHP arrays, fully opcached,
     * so URL matching and generation do no RouteCollection construction at runtime.
     *
     * Admin routes carry an `{_adminFrontName}` placeholder with a `/{_catchall}`
     * suffix for the key/value param convention (e.g. /admin/foo/bar/id/5/store/1).
     */
    private static function dumpRoutingFiles(string $outputDir, ?\Closure $log): void
    {
        if (self::$data['routes'] === []) {
            return;
        }

        $collection = new RouteCollection();

        foreach (self::$data['routes'] as $name => $route) {
            $path = $route['path'];
            $requirements = $route['requirements'];

            $defaults = array_merge($route['defaults'], [
                '_maho_controller' => $route['class'],
                '_maho_action' => $route['action'],
                '_maho_area' => $route['area'],
                '_maho_module' => $route['module'],
                '_maho_controller_name' => $route['controllerName'],
                '_maho_front_name' => self::resolveFrontNameKey($route),
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
        if (!self::atomicWrite($outputDir . '/maho_url_matcher.php', $matcherDumper->dump())) {
            self::logf($log, 'error', 'Failed to write %s/maho_url_matcher.php', $outputDir);
        }

        $generatorDumper = new CompiledUrlGeneratorDumper($collection);
        if (!self::atomicWrite($outputDir . '/maho_url_generator.php', $generatorDumper->dump())) {
            self::logf($log, 'error', 'Failed to write %s/maho_url_generator.php', $outputDir);
        }
    }
}
