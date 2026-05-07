<?php declare(strict_types=1);

namespace Maho\ComposerPlugin;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\GraphQl\Operation as GraphQlOperation;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Composer\IO\IOInterface;
use Maho\Config\ApiResource;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Compiles `#[Maho\Config\ApiResource]` attributes (subclass of API Platform's
 * `ApiResource`) into `vendor/composer/maho_api_permissions.php`.
 *
 * Consumed at runtime by `Maho\ApiPlatform\Security\ApiPermissionRegistry`,
 * which drives REST permission checks (`ApiUserVoter`), GraphQL permission
 * checks (`GraphQlPermissionListener`), and the admin role editor UI.
 *
 * Most fields are auto-derived from the API Platform metadata on the same
 * attribute — see `derivePermissionData()`. Authors only set the maho-specific
 * fields (`mahoPublicRead`, `mahoCustomerScoped`, `mahoDescription`) plus
 * occasional overrides when defaults are wrong.
 *
 * Must run after `AttributeCompiler::compile()` in the same process so the
 * `scanClasses()` active-module filter is already populated.
 *
 * `api-platform/core` is a require-dev of this plugin so phpstan / IDEs see
 * the real class hierarchy. At consumer install time it isn't pulled (dev
 * deps don't propagate); the runtime guard in `AutoloadPlugin::onPostAutoloadDumpCmd`
 * skips this whole compile step when `Maho\Config\ApiResource` isn't loaded.
 */
final class ApiPermissionCompiler
{
    /** Map of API Platform HTTP operation class → permission verb. */
    private const HTTP_OP_TO_VERB = [
        Get::class           => 'read',
        GetCollection::class => 'read',
        Post::class          => 'create',
        Put::class           => 'write',
        Patch::class         => 'write',
        Delete::class        => 'delete',
    ];

    /**
     * Default operation labels per HTTP verb. Used when `mahoOperations` is
     * not explicitly set and we have to derive the map from the parent's
     * `operations: [...]` array.
     */
    private const DEFAULT_OP_LABELS = [
        'read' => 'View',
        'create' => 'Create',
        'write' => 'Update',
        'delete' => 'Delete',
    ];

    public static function compile(string $outputDir, IOInterface $io): void
    {
        /** @var array<string, array{label: string, group: string, section: string, operations: array<string, string>, _class: class-string}> */
        $resources       = [];
        /** @var array<string, true> */
        $publicRead      = [];
        /** @var array<string, string> */
        $customerScoped  = [];
        /** @var array<string, string> */
        $segmentMap      = [];
        /** @var array<string, string> */
        $graphQlFieldMap = [];

        $scannedClasses = AttributeCompiler::scanClasses();

        // Temporary classmap autoloader so class_exists()/ReflectionClass work
        // on every scanned class (mirrors AttributeCompiler).
        $classMapAutoloader = static function (string $class) use ($scannedClasses): void {
            if (isset($scannedClasses[$class])) {
                require_once $scannedClasses[$class];
            }
        };
        spl_autoload_register($classMapAutoloader);

        try {
            foreach ($scannedClasses as $className => $filePath) {
                $contents = @file_get_contents($filePath);
                if ($contents === false) {
                    continue;
                }

                // Cheap pre-filter: a Maho ApiResource attribute means this
                // file references either the FQCN or the short `use Maho\Config\ApiResource`.
                if (strpos($contents, 'Maho\Config\ApiResource') === false) {
                    continue;
                }

                if (!class_exists($className)) {
                    continue;
                }

                $reflection = new ReflectionClass($className);
                if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                    continue;
                }

                // IS_INSTANCEOF lets us pick up the maho subclass plus any
                // hypothetical future deeper subclasses callers might define.
                $attributes = $reflection->getAttributes(ApiResource::class, ReflectionAttribute::IS_INSTANCEOF);
                foreach ($attributes as $attribute) {
                    try {
                        $resource = $attribute->newInstance();
                    } catch (\Throwable $e) {
                        $io->writeError(sprintf(
                            '  <warning>Skipping Maho\Config\ApiResource on %s: %s</warning>',
                            $className,
                            $e->getMessage(),
                        ));
                        continue;
                    }

                    $data = self::derivePermissionData($resource, $reflection, $io);
                    if ($data === null) {
                        continue; // attribute opted out (e.g. no derivable id)
                    }

                    $id            = $data['id'];
                    $entry         = $data['entry'];
                    $segments      = $data['segments'];
                    $graphQlFields = $data['graphQlFields'];

                    // Multiple ApiResource attributes on the same class with the same id
                    // are a legitimate pattern (different uriTemplates / operation sets that
                    // share one permission identity). Merge silently — the second attribute's
                    // entry wins, but its segments/graphQlFields are unioned below.
                    // Cross-class id collisions still warn (would mean two DTOs claim the
                    // same permission, almost always a bug).
                    if (isset($resources[$id]) && $resources[$id]['_class'] !== $className) {
                        $io->writeError(sprintf(
                            '  <warning>Duplicate ApiResource id "%s": %s and %s — second wins</warning>',
                            $id,
                            $resources[$id]['_class'],
                            $className,
                        ));
                    }

                    $entry['_class'] = $className;
                    $resources[$id] = $entry;

                    if ($resource->mahoPublicRead) {
                        $publicRead[$id] = true;
                    }
                    if ($resource->mahoCustomerScoped) {
                        $customerScoped[$id] = $resource->mahoDescription ?? '';
                    }

                    foreach ($segments as $segment) {
                        if (isset($segmentMap[$segment]) && $segmentMap[$segment] !== $id) {
                            $io->writeError(sprintf(
                                '  <warning>REST segment "%s" mapped to both "%s" and "%s"</warning>',
                                $segment,
                                $segmentMap[$segment],
                                $id,
                            ));
                        }
                        $segmentMap[$segment] = $id;
                    }

                    foreach ($graphQlFields as $field) {
                        if (isset($graphQlFieldMap[$field]) && $graphQlFieldMap[$field] !== $id) {
                            $io->writeError(sprintf(
                                '  <warning>GraphQL field "%s" mapped to both "%s" and "%s"</warning>',
                                $field,
                                $graphQlFieldMap[$field],
                                $id,
                            ));
                        }
                        $graphQlFieldMap[$field] = $id;
                    }
                }
            }
        } finally {
            spl_autoload_unregister($classMapAutoloader);
        }

        ksort($resources);
        ksort($segmentMap);
        ksort($graphQlFieldMap);
        ksort($customerScoped);

        // Strip the diagnostics-only `_class` key that's used to distinguish
        // same-class merges from cross-class collisions during scan.
        foreach ($resources as &$entry) {
            unset($entry['_class']);
        }
        unset($entry);

        $data = [
            'resources' => $resources,
            'publicRead' => array_keys($publicRead),
            'customerScoped' => $customerScoped,
            'segmentMap' => $segmentMap,
            'graphQlFieldMap' => $graphQlFieldMap,
        ];

        $content = '<?php return ' . var_export($data, true) . ";\n";
        if (file_put_contents($outputDir . '/maho_api_permissions.php', $content) === false) {
            $io->writeError(sprintf('  <error>Failed to write %s/maho_api_permissions.php</error>', $outputDir));
        }
    }

    /**
     * Derive the registry entry for one Maho\Config\ApiResource attribute instance.
     *
     * Returns null when the attribute carries no derivable identity (rare —
     * usually means shortName missing AND mahoId missing).
     *
     * Author overrides on the attribute always win over derived values.
     *
     * @param ReflectionClass<object> $reflection
     * @return array{
     *     id: string,
     *     entry: array{label: string, group: string, section: string, operations: array<string, string>},
     *     segments: list<string>,
     *     graphQlFields: list<string>,
     * }|null
     */
    private static function derivePermissionData(ApiResource $resource, ReflectionClass $reflection, IOInterface $io): ?array
    {
        // ---- id ----
        $id = $resource->mahoId ?? self::deriveIdFromShortName(
            $resource->getShortName() ?? $reflection->getShortName(),
        );
        if ($id === '') {
            $io->writeError(sprintf(
                '  <warning>Cannot derive resource id for %s — set mahoId explicitly</warning>',
                $reflection->getName(),
            ));
            return null;
        }

        // ---- label ----
        $label = $resource->mahoLabel ?? self::deriveLabelFromId($id);

        // ---- group (always 'Storefront' today) ----
        $group = $resource->mahoGroup ?? 'Storefront';

        // ---- section (from namespace: Mage\Catalog\Api\Foo → 'Catalog') ----
        $section = $resource->mahoSection ?? self::deriveSectionFromNamespace($reflection->getName());

        // ---- operations (derive from parent operations array) ----
        $operations = $resource->mahoOperations ?? self::deriveOperations($resource);

        // ---- REST segments — auto = just the resource id; mahoRestSegments
        //      is treated as an augment (additional segments) so callers don't
        //      have to repeat the primary id. Pass `[]` to suppress the default.
        $segments = self::deriveRestSegments($id);
        if ($resource->mahoRestSegments !== null) {
            $segments = array_values(array_unique([...$segments, ...$resource->mahoRestSegments]));
        }

        // ---- GraphQL fields — auto from graphQlOperations[].name plus optional
        //      mahoGraphQlFields for handler-defined fields the compiler can't see.
        $graphQlFields = self::deriveGraphQlFields($resource);
        if ($resource->mahoGraphQlFields !== null) {
            $graphQlFields = array_values(array_unique([...$graphQlFields, ...$resource->mahoGraphQlFields]));
        }

        $entry = [
            'label' => $label,
            'group' => $group,
            'section' => $section,
            'operations' => $operations,
        ];

        return [
            'id' => $id,
            'entry' => $entry,
            'segments' => $segments,
            'graphQlFields' => $graphQlFields,
        ];
    }

    /**
     * 'Cart' → 'carts', 'CmsPage' → 'cms-pages', 'ProductMedia' → 'product-medias'.
     * Naive plural-by-trailing-s; specific irregulars should set mahoId explicitly.
     */
    private static function deriveIdFromShortName(string $shortName): string
    {
        if ($shortName === '') {
            return '';
        }
        // CamelCase → kebab-case
        $kebab = strtolower((string) preg_replace('/(?<!^)([A-Z])/', '-$1', $shortName));
        // Plural: ends in 'y' → 'ies'; in 's'/'x'/'z'/'ch'/'sh' → 'es'; else → 's'
        if (str_ends_with($kebab, 'y') && preg_match('/[aeiou]y$/', $kebab) === 0) {
            return substr($kebab, 0, -1) . 'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/', $kebab) === 1) {
            return $kebab . 'es';
        }
        if (str_ends_with($kebab, 's')) {
            return $kebab; // already plural
        }
        return $kebab . 's';
    }

    /**
     * 'cms-pages' → 'CMS Pages', 'products' → 'Products'.
     * Acronyms get all-caps treatment when the kebab segment is ≤ 3 chars.
     */
    private static function deriveLabelFromId(string $id): string
    {
        $parts = explode('-', $id);
        $words = array_map(static function (string $part): string {
            return strlen($part) <= 3 ? strtoupper($part) : ucfirst($part);
        }, $parts);
        return implode(' ', $words);
    }

    /**
     * 'Mage\Catalog\Api\Product' → 'Catalog'
     * 'Maho\Blog\Api\BlogPost'   → 'Blog'
     * 'Maho\ApiPlatform\PermissionStubs\Foo' → 'PermissionStubs' (then caller overrides)
     *
     * Rule: take the segment immediately after the vendor namespace. If the
     * class lives outside any recognised pattern, fall back to the immediate
     * containing namespace segment.
     */
    private static function deriveSectionFromNamespace(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        // Strip the trailing class name
        array_pop($parts);
        if ($parts === []) {
            return 'Other';
        }
        // Mage\Catalog\Api\... or Maho\Blog\Api\... → 2nd segment ('Catalog'/'Blog')
        if (in_array($parts[0], ['Mage', 'Maho'], true) && isset($parts[1])) {
            return $parts[1];
        }
        // Fallback: last segment of the namespace
        // (`$parts` is non-empty here — the early return above handled the empty case).
        return $parts[count($parts) - 1];
    }

    /**
     * Walk the parent's operations array, classify each by HTTP verb, and
     * build a `['read' => 'View', ...]` map with default labels.
     *
     * @return array<string, string>
     */
    private static function deriveOperations(ApiResource $resource): array
    {
        $ops = $resource->getOperations();
        if ($ops === null) {
            // Operations omitted → API Platform applies defaults (Get, GetCollection, Post, Put, Delete)
            return self::DEFAULT_OP_LABELS;
        }

        $found = [];
        foreach ($ops as $op) {
            $verb = self::classifyOperation($op);
            if ($verb !== null) {
                $found[$verb] = self::DEFAULT_OP_LABELS[$verb];
            }
        }
        return $found;
    }

    /**
     * Map an operation instance to a permission verb. Falls back to the
     * `getMethod()` contract for custom HttpOperation subclasses.
     */
    private static function classifyOperation(object $op): ?string
    {
        $verb = self::HTTP_OP_TO_VERB[$op::class] ?? null;
        if ($verb !== null) {
            return $verb;
        }
        if ($op instanceof HttpOperation) {
            return match (strtoupper($op->getMethod())) {
                'GET', 'HEAD'  => 'read',
                'POST'         => 'create',
                'PUT', 'PATCH' => 'write',
                'DELETE'       => 'delete',
                default        => null,
            };
        }
        return null;
    }

    /**
     * Default REST segment is just the resource id itself.
     *
     * Deriving from `operations[].uriTemplate` first-segments is tempting but
     * wrong: nested URIs like `/orders/{id}/invoices` (declared on Invoice) would
     * register 'orders' as an Invoice segment, clobbering the actual mapping
     * for Order. Resources that legitimately have alternate top-level segments
     * (e.g. Cart serving both `/carts/*` and `/guest-carts/*`) must declare
     * `mahoRestSegments` explicitly.
     *
     * @return list<string>
     */
    private static function deriveRestSegments(string $id): array
    {
        return [$id];
    }

    /**
     * Pull externally-exposed `name:` values from `graphQlOperations` to build
     * the field map.
     *
     * Skips snake_case names (`item_query`, `collection_query`, `add_cart_item`):
     * those are API Platform's internal operation identifiers used for access
     * control routing, not the schema field name. Resources whose schema-exposed
     * field names diverge (and aren't camelCase `name:` values on the DTO)
     * should declare `mahoGraphQlFields` to surface them. Same applies to
     * handler-defined fields declared in `*MutationHandler` / `*QueryHandler`
     * classes that the compiler can't see from the DTO alone.
     *
     * @return list<string>
     */
    private static function deriveGraphQlFields(ApiResource $resource): array
    {
        $fields = [];
        $ops = $resource->getGraphQlOperations();
        if ($ops === null) {
            return [];
        }
        foreach ($ops as $op) {
            $name = $op->getName();
            if ($name === null || $name === '' || str_contains($name, '_')) {
                continue;
            }
            $fields[$name] = true;
        }
        return array_keys($fields);
    }
}
