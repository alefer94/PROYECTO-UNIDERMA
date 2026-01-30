<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WooCommerceService;
use App\Models\Laboratory;

class UpdateLaboratorySlugs extends Command
{
    protected $signature = 'woocommerce:update-lab-slugs';
    protected $description = 'Update laboratory category slugs to simplified pattern (lab-{CodLaboratorio})';

    protected WooCommerceService $woocommerceService;

    public function __construct(WooCommerceService $woocommerceService)
    {
        parent::__construct();
        $this->woocommerceService = $woocommerceService;
    }

    public function handle()
    {
        $this->info('🔧 Updating laboratory slugs to simplified pattern...');
        
        // Fetch all WooCommerce categories
        $page = 1;
        $wcCategories = [];
        
        do {
            $categories = $this->woocommerceService->getCategories([
                'per_page' => 100,
                'page' => $page,
            ]);
            
            $wcCategories = array_merge($wcCategories, $categories);
            $page++;
        } while (count($categories) === 100);
        
        // Filter laboratory categories (those starting with 'lab-')
        $labCategories = array_filter($wcCategories, function($cat) {
            return str_starts_with($cat->slug, 'lab-');
        });
        
        $this->info("Found " . count($labCategories) . " laboratory categories");
        
        // Show all slugs for debugging
        $this->newLine();
        $this->info("Current WooCommerce laboratory slugs:");
        foreach ($labCategories as $cat) {
            $this->line("  {$cat->id}: {$cat->slug}");
        }
        $this->newLine();
        
        // Get all laboratories from Laravel
        $laboratories = Laboratory::all();
        
        $toUpdate = [];
        $relinked = 0;
        
        foreach ($labCategories as $wcCat) {
            // Try to extract CodLaboratorio from slug using regex
            // Patterns: lab-{CodLaboratorio}-{name} or lab-{CodLaboratorio}
            if (preg_match('/^lab-([A-Z0-9]+)/', $wcCat->slug, $matches)) {
                $codLaboratorio = $matches[1];
                
                // Find the laboratory in Laravel
                $lab = $laboratories->firstWhere('CodLaboratorio', $codLaboratorio);
                
                if ($lab) {
                    $newSlug = 'lab-' . $lab->CodLaboratorio;
                    
                    if ($wcCat->slug !== $newSlug) {
                        $toUpdate[] = [
                            'id' => $wcCat->id,
                            'slug' => $newSlug
                        ];
                        
                        // Update Laravel if needed
                        if ($lab->WooCommerceCategoryId !== $wcCat->id) {
                            $lab->WooCommerceCategoryId = $wcCat->id;
                            $lab->save();
                            $relinked++;
                        }
                        
                        $this->line("  → {$wcCat->slug} => {$newSlug}");
                    }
                }
            }
        }
        
        $this->info("\nTo update: " . count($toUpdate));
        $this->info("Re-linked: {$relinked}");
        
        if (!empty($toUpdate)) {
            if ($this->confirm('Do you want to proceed with the update?', true)) {
                try {
                    $this->woocommerceService->batchCategories(['update' => $toUpdate]);
                    $this->info("✅ Successfully updated " . count($toUpdate) . " laboratory slugs!");
                } catch (\Exception $e) {
                    $this->error("❌ Failed to update: " . $e->getMessage());
                    return Command::FAILURE;
                }
            } else {
                $this->warn("Update cancelled.");
                return Command::SUCCESS;
            }
        } else {
            $this->info("✅ All slugs are already up to date!");
        }
        
        return Command::SUCCESS;
    }
}
