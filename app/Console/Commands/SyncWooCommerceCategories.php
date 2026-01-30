<?php

namespace App\Console\Commands;

use App\Models\CatalogCategory;
use App\Models\CatalogSubcategory;
use App\Models\CatalogType;
use App\Models\Laboratory;
use App\Models\Tag;
use App\Models\TagCategory;
use App\Models\TagSubcategory;
use App\Services\WooCommerceService;
use Illuminate\Console\Command;

class SyncWooCommerceCategories extends Command
{
    protected $signature = 'woocommerce:sync-categories';

    protected $description = 'Sync all categories to WooCommerce organized in 5 main groups';

    protected WooCommerceService $woocommerceService;

    // Root group definitions (immutable slugs)
    protected array $rootGroups = [
        [
            'name' => 'Por Catálogo',
            'slug' => 'group-1-by-catalog',
            'menu_order' => 1,
        ],
        [
            'name' => 'Por Característica',
            'slug' => 'group-2-by-characteristic',
            'menu_order' => 2,
        ],
        [
            'name' => 'Otros',
            'slug' => 'group-3-others',
            'menu_order' => 3,
        ],
        [
            'name' => 'Lanzamientos',
            'slug' => 'group-4-releases',
            'menu_order' => 4,
        ],
        [
            'name' => 'Marcas',
            'slug' => 'group-5-brands',
            'menu_order' => 5,
        ],
    ];

    public function __construct(WooCommerceService $woocommerceService)
    {
        parent::__construct();
        $this->woocommerceService = $woocommerceService;
    }

    public function handle()
    {
        $startTime = microtime(true);

        $this->info('🚀 Starting comprehensive category synchronization...');

        // Step 1: Fetch all WooCommerce categories
        $wcCategories = $this->fetchAllWooCommerceCategories();
        if ($wcCategories === false) {
            return Command::FAILURE;
        }

        $this->info('Total WooCommerce categories fetched: '.count($wcCategories));

        // Step 2: Create/Verify root groups
        $rootGroupIds = $this->createOrVerifyRootGroups($wcCategories);
        if ($rootGroupIds === false) {
            return Command::FAILURE;
        }

        // Step 3: Fetch Laravel data
        $laravelData = $this->fetchLaravelData();

        // Step 4: Sync Group 1 - Por Catálogo (Tags + Type A6)
        $this->syncGroup1($rootGroupIds['group-1-by-catalog'], $laravelData, $wcCategories);

        // Step 5: Sync Group 2 - Por Característica (Type A9)
        $this->syncGroup2($rootGroupIds['group-2-by-characteristic'], $laravelData, $wcCategories);

        // Step 6: Sync Group 3 - Otros (Types 20 & 18)
        if (isset($rootGroupIds['group-3-others'])) {
            $this->syncGroup3($rootGroupIds['group-3-others'], $laravelData, $wcCategories);
        } else {
            $this->warn("  ⚠ Skipping Group 3 sync: 'Otros' root group ID missing");
        }

        // Step 7: Sync Group 5 - Marcas (Laboratories)
        if (isset($rootGroupIds['group-5-brands'])) {
            $this->syncGroup5($rootGroupIds['group-5-brands'], $laravelData, $wcCategories);
        } else {
            $this->warn("  ⚠ Skipping Group 5 sync: 'Marcas' root group ID missing");
        }

        // Step 8: Clean up orphaned categories
        $this->cleanupOrphanedCategories($wcCategories, $laravelData, $rootGroupIds);

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        $this->info("✅ Synchronization completed in {$duration} seconds!");

        return Command::SUCCESS;
    }

    /**
     * Fetch all WooCommerce categories with pagination
     */
    protected function fetchAllWooCommerceCategories()
    {
        $page = 1;
        $wcCategories = [];

        try {
            while (true) {
                $categories = $this->woocommerceService->getCategories([
                    'per_page' => 100,
                    'page' => $page,
                ]);

                if (empty($categories)) {
                    break;
                }

                $wcCategories = array_merge($wcCategories, $categories);
                $page++;

                // Safety break to prevent infinite loops (unlikely with 100 per page)
                if ($page > 100) {
                    break;
                }
            }

            return $wcCategories;
        } catch (\Exception $e) {
            $this->handleWooCommerceError($e);

            return false;
        }
    }

    /**
     * Create or verify the 5 root groups
     */
    protected function createOrVerifyRootGroups(&$wcCategories)
    {
        $this->newLine();
        $this->info('=== CREATING/VERIFYING ROOT GROUPS ===');

        $rootGroupIds = [];
        $toCreate = [];
        $toUpdate = [];

        foreach ($this->rootGroups as $group) {
            $found = $this->findBySlug($wcCategories, $group['slug']);

            // Fallback: search by name if slug lookup fails
            if (! $found) {
                $found = $this->findByName($wcCategories, $group['name'], 0);
                if ($found) {
                    $this->warn("  ⚠ Root group '{$group['name']}' found by name but slug mismatched (Slug: '{$found->slug}' vs Expected: '{$group['slug']}')");
                }
            }

            if ($found) {
                $rootGroupIds[$group['slug']] = $found->id;
                $this->info("  ✓ Found '{$group['name']}' (ID: {$found->id})");

                // Check if menu_order needs update
                if ($found->menu_order != $group['menu_order']) {
                    $toUpdate[] = [
                        'id' => $found->id,
                        'menu_order' => $group['menu_order'],
                    ];
                }
            } else {
                $this->warn("  ✗ '{$group['name']}' not found, will create");
                $toCreate[] = $group;
            }
        }

        // Create missing groups
        if (! empty($toCreate)) {
            try {
                $createData = array_map(function ($group) {
                    return [
                        'name' => $group['name'],
                        'slug' => $group['slug'],
                        'parent' => 0,
                        'menu_order' => $group['menu_order'],
                    ];
                }, $toCreate);

                $response = $this->woocommerceService->batchCategories(['create' => $createData]);

                if (isset($response->create)) {
                    foreach ($response->create as $index => $created) {
                        $slug = $toCreate[$index]['slug'];
                        if (! empty($created->id)) {
                            $rootGroupIds[$slug] = $created->id;
                            $this->info("  ✓ Created '{$created->name}' (ID: {$created->id})");

                            // Add to wcCategories so children can find their parent
                            if (isset($created->slug)) {
                                $wcCategories[] = $created;
                            }
                        } else {
                            // SELF-HEALING: If it already exists, extract the ID from the error
                            if (isset($created->error->code) && $created->error->code === 'term_exists') {
                                $resourceId = $created->error->data->resource_id ?? null;
                                if ($resourceId) {
                                    $rootGroupIds[$slug] = $resourceId;
                                    $this->info("  ✓ Re-linked '{$toCreate[$index]['name']}' from term_exists error (ID: {$resourceId})");

                                    continue;
                                }
                            }

                            $this->error("  ✗ Failed to create '{$toCreate[$index]['name']}': ID missing in response");
                            if (isset($created->error)) {
                                $this->error('    Error: '.json_encode($created->error));
                            }
                        }
                    }
                } else {
                    $this->error('  ✗ Batch create failed: '.json_encode($response));
                }
            } catch (\Exception $e) {
                $this->error('Failed to create root groups: '.$e->getMessage());

                return false;
            }
        }

        // Update menu_order if needed
        if (! empty($toUpdate)) {
            try {
                $this->woocommerceService->batchCategories(['update' => $toUpdate]);
                $this->info('  ✓ Updated menu_order for '.count($toUpdate).' groups');
            } catch (\Exception $e) {
                $this->warn('Failed to update menu_order: '.$e->getMessage());
            }
        }

        return $rootGroupIds;
    }

    /**
     * Fetch all Laravel data
     */
    protected function fetchLaravelData()
    {
        $this->newLine();
        $this->info('=== FETCHING LARAVEL DATA ===');

        $data = [
            // Tags
            'tagCategories' => TagCategory::all(),
            'tagSubcategories' => TagSubcategory::all(),
            'tags' => Tag::all(),

            // Catalog Types
            'catalogTypeA6' => CatalogType::where('Tipcat', 'A6')->first(),
            'catalogTypeA9' => CatalogType::where('Tipcat', 'A9')->first(),
            'catalogTypes2018' => CatalogType::whereIn('Tipcat', ['20', '18'])->get(),

            // Catalog Categories
            'catalogCategoriesA6' => CatalogCategory::where('CodTipcat', 'A6')->get(),
            'catalogCategoriesA9' => CatalogCategory::where('CodTipcat', 'A9')->get(),
            'catalogCategories2018' => CatalogCategory::whereIn('CodTipcat', ['20', '18'])->get(),

            // Catalog Subcategories
            'catalogSubcategoriesA6' => CatalogSubcategory::where('CodTipcat', 'A6')->get(),
            'catalogSubcategoriesA9' => CatalogSubcategory::where('CodTipcat', 'A9')->get(),
            'catalogSubcategories2018' => CatalogSubcategory::whereIn('CodTipcat', ['20', '18'])->get(),

            // Laboratories
            'laboratories' => Laboratory::all(),
        ];

        $this->info('  TagCategories: '.count($data['tagCategories']));
        $this->info('  TagSubcategories: '.count($data['tagSubcategories']));
        $this->info('  Tags: '.count($data['tags']));
        $this->info('  Laboratories: '.count($data['laboratories']));

        return $data;
    }

    /**
     * Sync Group 1: Por Catálogo (Tags + Type A6)
     */
    protected function syncGroup1($groupId, $laravelData, &$wcCategories)
    {
        $this->newLine();
        $this->info('=== SYNCING GROUP 1: POR CATÁLOGO ===');

        // Sync Tag hierarchy
        $this->syncTagHierarchy($groupId, $laravelData, $wcCategories);

        // Sync CatalogType A6 hierarchy
        if ($laravelData['catalogTypeA6']) {
            $this->syncCatalogTypeHierarchy(
                $groupId,
                $laravelData['catalogTypeA6'],
                $laravelData['catalogCategoriesA6'],
                $laravelData['catalogSubcategoriesA6'],
                $wcCategories
            );
        }
    }

    /**
     * Sync Group 2: Por Característica (Type A9)
     */
    protected function syncGroup2($groupId, $laravelData, &$wcCategories)
    {
        $this->newLine();
        $this->info('=== SYNCING GROUP 2: POR CARACTERÍSTICA ===');

        if ($laravelData['catalogTypeA9']) {
            $this->syncCatalogTypeHierarchy(
                $groupId,
                $laravelData['catalogTypeA9'],
                $laravelData['catalogCategoriesA9'],
                $laravelData['catalogSubcategoriesA9'],
                $wcCategories
            );
        }
    }

    /**
     * Sync Group 3: Otros (Types 20 & 18)
     */
    protected function syncGroup3($groupId, $laravelData, &$wcCategories)
    {
        $this->newLine();
        $this->info('=== SYNCING GROUP 3: OTROS ===');

        foreach ($laravelData['catalogTypes2018'] as $catalogType) {
            $categories = $laravelData['catalogCategories2018']->where('CodTipcat', $catalogType->Tipcat);
            $subcategories = $laravelData['catalogSubcategories2018']->where('CodTipcat', $catalogType->Tipcat);

            $this->syncCatalogTypeHierarchy(
                $groupId,
                $catalogType,
                $categories,
                $subcategories,
                $wcCategories
            );
        }
    }

    /**
     * Sync Group 5: Marcas (Laboratories)
     */
    protected function syncGroup5($groupId, $laravelData, &$wcCategories)
    {
        $this->newLine();
        $this->info('=== SYNCING GROUP 5: MARCAS ===');

        $toCreate = [];
        $toUpdate = [];
        $linkedCount = 0;

        foreach ($laravelData['laboratories'] as $index => $lab) {
            // Simplified slug: only lab-{CodLaboratorio} (lowercase for WooCommerce compatibility)
            $expectedSlug = $this->generateSlug('lab', $lab->CodLaboratorio);

            $found = null;
            if ($lab->WooCommerceCategoryId) {
                $found = $this->findById($wcCategories, $lab->WooCommerceCategoryId);

                if ($found) {
                    // SLUG CONSISTENCY CHECK: If the ID exists but belongs to a different Own-System slug,
                    // we invalidate the link to force a correct re-lookup by slug or name.
                    if ($this->isOurCategory($found->slug) && $found->slug !== $expectedSlug) {
                        $this->warn("    ⚠ Inconsistent link for Lab '{$lab->NomLaboratorio}': ID {$found->id} has slug '{$found->slug}' vs expected '{$expectedSlug}'. Invalidating.");
                        $lab->WooCommerceCategoryId = null;
                        $lab->save();
                        $found = null;
                    }
                }
            }

            // If not found by ID (or invalidated), search by slug
            if (! $found) {
                $found = $this->findBySlug($wcCategories, $expectedSlug);
                if ($found) {
                    $linkedCount++;
                }
            }

            // If still not found, search by name (Strictly Hierarchy Aware - Parent is Group ID)
            if (! $found) {
                $found = $this->findByName($wcCategories, $lab->NomLaboratorio, $groupId);
                if ($found) {
                    $this->warn("    ⚠ Found Lab '{$lab->NomLaboratorio}' by name but slug mismatched (Slug: '{$found->slug}' vs Expected: '{$expectedSlug}')");
                }
            }
            if ($found) {
                // Check for discrepancies (Case-Insensitive for Name)
                $wcName = trim(preg_replace('/\s+/', ' ', html_entity_decode($found->name)));
                $laravelName = trim(preg_replace('/\s+/', ' ', $lab->NomLaboratorio));
                $expectedDescription = $lab->FlgNuevo ? '1' : '0';

                if (strcasecmp($wcName, $laravelName) !== 0 ||
                    $found->parent != $groupId ||
                    $found->menu_order != $index ||
                    $found->description != $expectedDescription ||
                    (isset($found->slug) && $found->slug !== $expectedSlug)) {

                    $toUpdate[] = [
                        'id' => $found->id,
                        'name' => $lab->NomLaboratorio,
                        'slug' => $expectedSlug,
                        'parent' => $groupId,
                        'menu_order' => $index,
                        'description' => $expectedDescription,
                    ];
                }

                // Always sync ID back to Laravel if missing or wrong
                if ($lab->WooCommerceCategoryId !== $found->id) {
                    $lab->WooCommerceCategoryId = $found->id;
                    $lab->save();
                }
            } else {
                $toCreate[] = ['model' => $lab, 'index' => $index];
            }
        }

        $this->info("  Linked: {$linkedCount}");
        $this->info('  To create: '.count($toCreate));
        $this->info('  To update: '.count($toUpdate));

        // Execute batch updates
        if (! empty($toUpdate)) {
            try {
                $this->woocommerceService->batchCategories(['update' => $toUpdate]);
                $this->info('  ✓ Updated '.count($toUpdate).' laboratories');
            } catch (\Exception $e) {
                $this->error('  ✗ Failed to update: '.$e->getMessage());
            }
        }

        // Execute batch creations
        if (! empty($toCreate)) {
            try {
                $createData = array_map(function ($item) use ($groupId) {
                    return [
                        'name' => $item['model']->NomLaboratorio,
                        'slug' => $this->generateSlug('lab', $item['model']->CodLaboratorio),
                        'parent' => $groupId,
                        'menu_order' => $item['index'],
                        'description' => $item['model']->FlgNuevo ? '1' : '0',
                    ];
                }, $toCreate);

                $response = $this->woocommerceService->batchCategories(['create' => $createData]);

                if (isset($response->create)) {
                    foreach ($response->create as $index => $created) {
                        $toCreate[$index]['model']->WooCommerceCategoryId = $created->id;
                        $toCreate[$index]['model']->save();

                        // CRITICAL FIX: Add newly created category to $wcCategories array
                        if (isset($created->slug)) {
                            $wcCategories[] = $created;
                        }
                    }
                    $this->info('  ✓ Created '.count($response->create).' laboratories');
                }
            } catch (\Exception $e) {
                $this->error('  ✗ Failed to create: '.$e->getMessage());
            }
        }
    }

    /**
     * Sync Tag hierarchy (TagCategory -> TagSubcategory -> Tag)
     */
    protected function syncTagHierarchy($groupId, $laravelData, &$wcCategories)
    {
        $this->info('  Syncing Tag hierarchy...');

        // Level 1: TagCategories
        $this->syncLevel($laravelData['tagCategories'], $groupId, 'cat', 'IdClasificador', 'Nombre', 'Orden', $wcCategories);

        // Level 2: TagSubcategories (All at once)
        $subcategoriesToSync = [];
        foreach ($laravelData['tagCategories'] as $cat) {
            if ($cat->WooCommerceCategoryId) {
                $related = $laravelData['tagSubcategories']->where('IdClasificador', $cat->IdClasificador);
                foreach ($related as $subcat) {
                    $subcategoriesToSync[] = [
                        'model' => $subcat,
                        'parentId' => $cat->WooCommerceCategoryId,
                    ];
                }
            }
        }
        if (! empty($subcategoriesToSync)) {
            $this->syncLevelBatch($subcategoriesToSync, 'subcat', 'IdSubClasificador', 'Nombre', 'Orden', $wcCategories);
        }

        // Level 3: Tags (All at once)
        $tagsToSync = [];
        foreach ($laravelData['tagSubcategories'] as $subcat) {
            if ($subcat->WooCommerceCategoryId) {
                $related = $laravelData['tags']->where('IdSubClasificador', $subcat->IdSubClasificador);
                foreach ($related as $tag) {
                    $tagsToSync[] = [
                        'model' => $tag,
                        'parentId' => $subcat->WooCommerceCategoryId,
                    ];
                }
            }
        }
        if (! empty($tagsToSync)) {
            $this->syncLevelBatch($tagsToSync, 'tag', 'IdTag', 'Nombre', 'Orden', $wcCategories);
        }

        $this->info('  ✓ Tag hierarchy synced');
    }

    /**
     * Sync CatalogType hierarchy (Type -> Category -> Subcategory)
     */
    protected function syncCatalogTypeHierarchy($groupId, $catalogType, $categories, $subcategories, &$wcCategories)
    {
        $this->info("  Syncing CatalogType {$catalogType->Tipcat} hierarchy...");

        // Level 1: CatalogType
        $this->syncLevel(
            collect([$catalogType]),
            $groupId,
            'type',
            'Tipcat',
            'Nombre',
            null,
            $wcCategories
        );

        $typeId = $catalogType->WooCommerceCategoryId;

        if (! $typeId) {
            $this->warn("  Failed to sync CatalogType {$catalogType->Tipcat}");

            return;
        }

        // Level 2: CatalogCategories
        $categoriesToSync = [];
        foreach ($categories as $cat) {
            $categoriesToSync[] = [
                'model' => $cat,
                'parentId' => $catalogType->WooCommerceCategoryId,
            ];
        }
        $this->syncLevelBatch($categoriesToSync, 'typecat', 'CodClasificador', 'Nombre', null, $wcCategories);

        // Level 3: CatalogSubcategories
        $subcategoriesToSync = [];
        foreach ($categories as $cat) {
            if ($cat->WooCommerceCategoryId) {
                $related = $subcategories->where('CodClasificador', $cat->CodClasificador);
                foreach ($related as $subcat) {
                    $subcategoriesToSync[] = [
                        'model' => $subcat,
                        'parentId' => $cat->WooCommerceCategoryId,
                    ];
                }
            }
        }
        if (! empty($subcategoriesToSync)) {
            $this->syncLevelBatch($subcategoriesToSync, 'typesub', 'CodSubClasificador', 'Nombre', null, $wcCategories);
        }

        $this->info("  ✓ CatalogType {$catalogType->Tipcat} hierarchy synced");
    }

    /**
     * Sync a collection of items at the same level
     */
    protected function syncLevel($items, $parentId, $prefix, $idField, $nameField, $orderField, &$wcCategories)
    {
        $batch = [];
        foreach ($items as $index => $item) {
            $batch[] = [
                'model' => $item,
                'parentId' => $parentId,
                'index' => $index,
            ];
        }

        return $this->syncLevelBatch($batch, $prefix, $idField, $nameField, $orderField, $wcCategories);
    }

    /**
     * Internal method to sync a batch of items (possibly with different parents)
     */
    protected function syncLevelBatch($batchItems, $prefix, $idField, $nameField, $orderField, &$wcCategories)
    {
        $toCreate = [];
        $toUpdate = [];

        foreach ($batchItems as $batchItem) {
            $model = $batchItem['model'];
            $parentId = $batchItem['parentId'];
            $fallbackOrder = $batchItem['index'] ?? 0;

            $expectedSlug = $this->generateSlug($prefix, $model->$idField);
            $menuOrder = $orderField && isset($model->$orderField) ? $model->$orderField : $fallbackOrder;

            $found = null;
            if ($model->WooCommerceCategoryId) {
                $found = $this->findById($wcCategories, $model->WooCommerceCategoryId);

                if ($found) {
                    // SLUG CONSISTENCY CHECK: If the ID exists but belongs to a different Own-System slug,
                    // we invalidate the link to force a correct re-lookup by slug or name.
                    if ($this->isOurCategory($found->slug) && $found->slug !== $expectedSlug) {
                        $this->warn("    ⚠ Inconsistent link for '{$model->$nameField}': ID {$found->id} has slug '{$found->slug}' vs expected '{$expectedSlug}'. Invalidating.");
                        $model->WooCommerceCategoryId = null;
                        $model->save();
                        $found = null;
                    }
                }
            }

            // If not found by ID (or invalidated), search by slug
            if (! $found) {
                $found = $this->findBySlug($wcCategories, $expectedSlug);
            }

            // If still not found, search by name (Strictly Hierarchy Aware)
            if (! $found) {
                $found = $this->findByName($wcCategories, $model->$nameField, $parentId);
                if ($found) {
                    $this->warn("    ⚠ Found '{$model->$nameField}' by name but slug mismatched (Slug: '{$found->slug}' vs Expected: '{$expectedSlug}')");
                }
            }

            if ($found) {
                // Check for discrepancies (Case-Insensitive for Name)
                $wcName = trim(preg_replace('/\s+/', ' ', html_entity_decode($found->name)));
                $laravelName = trim(preg_replace('/\s+/', ' ', $model->$nameField));

                if (strcasecmp($wcName, $laravelName) !== 0 ||
                    $found->parent != $parentId ||
                    $found->menu_order != $menuOrder ||
                    $found->slug !== $expectedSlug) {

                    $toUpdate[] = [
                        'id' => $found->id,
                        'name' => $model->$nameField,
                        'slug' => $expectedSlug,
                        'parent' => $parentId,
                        'menu_order' => $menuOrder,
                    ];
                }

                // Always sync ID back to Laravel if missing or wrong
                if ($model->WooCommerceCategoryId !== $found->id) {
                    $model->WooCommerceCategoryId = $found->id;
                    $model->save();
                }
            } else {
                $toCreate[] = [
                    'model' => $model,
                    'parentId' => $parentId,
                    'index' => $menuOrder,
                ];
            }
        }

        // Execute batch update
        // Execute batch update in chunks of 100
        if (! empty($toUpdate)) {
            $updateBatches = array_chunk($toUpdate, 100);
            foreach ($updateBatches as $batchIndex => $batch) {
                try {
                    $this->woocommerceService->batchCategories(['update' => $batch]);
                    $this->info('  ✓ Updated batch '.($batchIndex + 1).' of '.count($updateBatches).' ('.count($batch).' items)');
                } catch (\Exception $e) {
                    $this->warn('  ✗ Failed to update batch '.($batchIndex + 1).': '.$e->getMessage());
                }
            }
        }

        // Execute batch create
        // Execute batch create in chunks of 100
        if (! empty($toCreate)) {
            $createBatches = array_chunk($toCreate, 100);
            $totalCreated = 0;

            foreach ($createBatches as $batchIndex => $batch) {
                try {
                    $createData = array_map(function ($item) use ($prefix, $idField, $nameField) {
                        return [
                            'name' => $item['model']->$nameField,
                            'slug' => $this->generateSlug($prefix, $item['model']->$idField),
                            'parent' => $item['parentId'],
                            'menu_order' => $item['index'],
                        ];
                    }, $batch);

                    $response = $this->woocommerceService->batchCategories(['create' => $createData]);

                    if (isset($response->create)) {
                        foreach ($response->create as $idx => $created) {
                            if (! empty($created->id)) {
                                $batch[$idx]['model']->WooCommerceCategoryId = $created->id;
                                $batch[$idx]['model']->save();

                                // Add newly created category to $wcCategories array
                                if (isset($created->slug)) {
                                    $wcCategories[] = $created;
                                }
                            } else {
                                // SELF-HEALING: Extract ID from term_exists error
                                if (isset($created->error->code) && $created->error->code === 'term_exists') {
                                    $resourceId = $created->error->data->resource_id ?? null;
                                    if ($resourceId) {
                                        $batch[$idx]['model']->WooCommerceCategoryId = $resourceId;
                                        $batch[$idx]['model']->save();
                                        $this->info("    ✓ Re-linked '{$batch[$idx]['model']->$nameField}' from term_exists error (ID: {$resourceId})");

                                        continue;
                                    }
                                }

                                $this->error("    ✗ Failed to create '{$batch[$idx]['model']->$nameField}': ID missing");
                                if (isset($created->error)) {
                                    $this->error('      Error: '.json_encode($created->error));
                                }
                            }
                        }
                        $totalCreated += count($response->create);
                        $this->info('  ✓ Created batch '.($batchIndex + 1).' of '.count($createBatches).' ('.count($response->create).' items)');
                    }
                } catch (\Exception $e) {
                    $this->error('  ✗ Failed to create batch '.($batchIndex + 1).': '.$e->getMessage());
                }
            }
            $this->info("  ✓ Total created: $totalCreated items");
        }

        return true;
    }

    /**
     * Clean up orphaned categories
     */
    protected function cleanupOrphanedCategories($wcCategories, $laravelData, $rootGroupIds)
    {
        $this->newLine();
        $this->info('=== CLEANING UP ORPHANED CATEGORIES ===');

        // IMPORTANT: Re-fetch Laravel data to get updated WooCommerceCategoryId after re-linking
        $freshLaravelData = $this->fetchLaravelData();

        // Collect all valid WooCommerce IDs from Laravel
        $validIds = [];

        // All models with WooCommerceCategoryId
        $models = [
            $freshLaravelData['tagCategories'],
            $freshLaravelData['tagSubcategories'],
            $freshLaravelData['tags'],
            $freshLaravelData['catalogCategoriesA6'],
            $freshLaravelData['catalogCategoriesA9'],
            $freshLaravelData['catalogCategories2018'],
            $freshLaravelData['catalogSubcategoriesA6'],
            $freshLaravelData['catalogSubcategoriesA9'],
            $freshLaravelData['catalogSubcategories2018'],
            $freshLaravelData['laboratories'],
        ];

        // Root groups
        foreach ($rootGroupIds as $id) {
            $validIds[] = $id;
        }

        if ($freshLaravelData['catalogTypeA6']) {
            $validIds[] = $freshLaravelData['catalogTypeA6']->WooCommerceCategoryId;
        }
        if ($freshLaravelData['catalogTypeA9']) {
            $validIds[] = $freshLaravelData['catalogTypeA9']->WooCommerceCategoryId;
        }
        foreach ($freshLaravelData['catalogTypes2018'] as $type) {
            $validIds[] = $type->WooCommerceCategoryId;
        }

        foreach ($models as $collection) {
            foreach ($collection as $model) {
                if ($model->WooCommerceCategoryId) {
                    $validIds[] = $model->WooCommerceCategoryId;
                }
            }
        }

        $validIds = array_filter(array_unique($validIds));
        $rootGroupValues = array_values($rootGroupIds);

        // Find orphaned categories
        $orphaned = [];
        foreach ($wcCategories as $wcCat) {
            // Is it one of our categories (by slug pattern)?
            $isOursBySlug = isset($wcCat->slug) ? $this->isOurCategory($wcCat->slug) : false;

            // Is it under one of our root groups?
            $isUnderOurRoot = $this->isDescendantOfGroups($wcCat, $wcCategories, $rootGroupValues);

            // SPECIAL CASE: Is it one of the root groups itself?
            // We MUST NOT delete root groups even if they aren't in Laravel
            $isRootGroup = in_array($wcCat->id, $rootGroupValues) || (isset($wcCat->slug) && str_starts_with($wcCat->slug, 'group-'));

            // If it's ours or under our roots, but NOT in valid list, it's an intruder
            if (($isOursBySlug || $isUnderOurRoot) && ! in_array($wcCat->id, $validIds) && ! $isRootGroup) {
                $orphaned[] = $wcCat->id;
            }
        }

        $this->info('Found '.count($orphaned).' orphaned categories');

        if (! empty($orphaned)) {
            // Process in batches of 100 (WooCommerce API limit)
            $batches = array_chunk($orphaned, 100);
            $totalDeleted = 0;

            foreach ($batches as $batchIndex => $batch) {
                try {
                    $this->woocommerceService->batchCategories([
                        'delete' => $batch,
                        'force' => true,
                    ]);
                    $totalDeleted += count($batch);
                    $this->info('  ✓ Deleted batch '.($batchIndex + 1).' of '.count($batches).': '.count($batch).' categories');
                } catch (\Exception $e) {
                    $this->warn('  Failed to delete batch '.($batchIndex + 1).': '.$e->getMessage());
                }
            }

            $this->info("  ✓ Total deleted: {$totalDeleted} orphaned categories");
        }
    }

    /**
     * Check if a category is a descendant of any of the given group IDs
     */
    protected function isDescendantOfGroups($category, $allCategories, $groupIds)
    {
        if ($category->parent === 0) {
            return false;
        }

        $currentParent = $category->parent;
        $visited = []; // Prevent infinite loops

        while ($currentParent !== 0 && ! in_array($currentParent, $visited)) {
            if (in_array($currentParent, $groupIds)) {
                return true;
            }

            $visited[] = $currentParent;
            $parentCat = $this->findById($allCategories, $currentParent);

            if (! $parentCat) {
                break;
            }

            $currentParent = $parentCat->parent;
        }

        return false;
    }

    /**
     * Check if category belongs to our system
     */
    protected function isOurCategory($slug)
    {
        $patterns = ['group-', 'cat-', 'subcat-', 'tag-', 'type-', 'typecat-', 'typesub-', 'lab-'];

        foreach ($patterns as $pattern) {
            if (str_starts_with($slug, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find category by ID in WooCommerce array
     */
    protected function findById(array $categories, int $id)
    {
        foreach ($categories as $category) {
            if ($category->id == $id) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Find category by name in WooCommerce array
     */
    protected function findByName(array $categories, string $name, $parentId = null)
    {
        $normalizedName = trim(strtolower($name));
        foreach ($categories as $category) {
            if (isset($category->name) && trim(strtolower($category->name)) === $normalizedName) {
                if ($parentId !== null && $category->parent != $parentId) {
                    continue;
                }

                return $category;
            }
        }

        return null;
    }

    /**
     * Find category by slug in WooCommerce array
     */
    protected function findBySlug(array $categories, string $slug)
    {
        foreach ($categories as $category) {
            if (isset($category->slug) && $category->slug === $slug) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Generate unique slug with pattern: prefix-id-name
     */
    protected function generateSlug(string $prefix, $id): string
    {
        return strtolower("{$prefix}-{$id}");
    }

    /**
     * Handle WooCommerce API errors
     */
    protected function handleWooCommerceError(\Exception $e)
    {
        $errorMessage = $e->getMessage();

        $this->error('✗ Failed to connect to WooCommerce API');

        if (str_contains($errorMessage, 'timed out') || str_contains($errorMessage, 'timeout')) {
            $this->error('Reason: Connection timeout - The WooCommerce server is taking too long to respond');
            $this->line('Suggestion: Check your internet connection or try again later');
        } elseif (str_contains($errorMessage, 'Could not resolve host')) {
            $this->error('Reason: Cannot reach WooCommerce server - The API endpoint is unreachable');
            $this->line('Suggestion: Verify the WooCommerce URL in your .env file');
        } elseif (str_contains($errorMessage, 'Unauthorized') || str_contains($errorMessage, '401')) {
            $this->error('Reason: Authentication failed - Invalid API credentials');
            $this->line('Suggestion: Check your WooCommerce API key and secret in .env');
        } elseif (str_contains($errorMessage, 'Connection refused')) {
            $this->error('Reason: WooCommerce API is offline or unreachable');
            $this->line('Suggestion: Verify that your WooCommerce site is online');
        } elseif (str_contains($errorMessage, 'JSON ERROR') || str_contains($errorMessage, 'Syntax error')) {
            $this->error('Reason: Invalid response from WooCommerce - The API returned malformed data');
            $this->line('Suggestion: Check if your WooCommerce site is working properly or has a server error');
        } else {
            $this->error('Reason: '.$errorMessage);
        }
    }
}
