<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$missingIds = [
    664, 665, 666, 667, 668, 669, 
    670, 671, 672, 673, 674, 675, 
    676, 677, 678, 679
];

echo "Analyzing " . count($missingIds) . " missing IDs...\n\n";

foreach ($missingIds as $id) {
    echo "ID [$id]: ";
    $found = false;
    
    // Check Subcategories
    $sub = App\Models\CatalogSubcategory::where('WooCommerceCategoryId', $id)->first();
    if ($sub) { 
        echo "CatalogSubcategory -> {$sub->Nombre} (Parent Cat: {$sub->CodClasificador})\n"; 
        $found = true; 
    }
    
    // Check Categories
    if (!$found) {
        $cat = App\Models\CatalogCategory::where('WooCommerceCategoryId', $id)->first();
        if ($cat) { 
            echo "CatalogCategory -> {$cat->Nombre} (Parent Type: {$cat->CodTipcat})\n"; 
            $found = true; 
        }
    }
    
    // Check Types
    if (!$found) {
        $type = App\Models\CatalogType::where('WooCommerceCategoryId', $id)->first();
        if ($type) { 
            echo "CatalogType -> {$type->Nombre}\n"; 
            $found = true; 
        }
    }
    
    // Check Tags
    if (!$found) {
        $tag = App\Models\Tag::where('WooCommerceCategoryId', $id)->first();
        if ($tag) { 
            // Handle case where Tag table might have issue with name column
            try {
                $tagName = $tag->NomTag ?? $tag->Nombre ?? 'Unknown Name';
                echo "Tag -> {$tagName}\n";
            } catch (Exception $e) {
                echo "Tag (Name Error)\n";
            }
            $found = true; 
        }
    }
    
    // Check Laboratories
    if (!$found) {
        $lab = App\Models\Laboratory::where('WooCommerceCategoryId', $id)->first();
        if ($lab) { 
            echo "Laboratory -> {$lab->Nombre}\n"; 
            $found = true; 
        }
    }
    
    if (!$found) {
        echo "NOT FOUND in any table\n";
    }
}
