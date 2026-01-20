<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ProductImageService
{
    protected $pathCache = [];
    protected $prefixScanned = [];

    /**
     * Normalizar ruta FTP de Windows a Unix
     * S:\P\A9\A90000000130001 → P/A9/A90000000130001
     */
    public function normalizeFtpPath(?string $ftpPath): ?string
    {
        if (empty($ftpPath)) {
            return null;
        }

        return str_replace(['S:\\', '\\'], ['', '/'], trim($ftpPath));
    }

    /**
     * Extraer código de producto desde ruta FTP
     * P/A9/A90000000130001 → A90000000130001
     */
    public function extractProductCode(?string $ftpPath): ?string
    {
        if (empty($ftpPath)) {
            return null;
        }

        $normalized = $this->normalizeFtpPath($ftpPath);

        return basename($normalized);
    }

    /**
     * Obtener todas las imágenes de un producto
     * Retorna array de filenames
     */
    public function getProductImages(string $productCode, ?string $relativePath = null): array
    {
        // $basePath = storage_path('app/private/ftp_sync');
        $basePath = storage_path('app/private/ftp_sync');
        $directoryToScan = null;

        // Si tenemos el path relativo (desde Home), usarlo directamente
        if ($relativePath) {
            $fullPath = $basePath.'/'.$relativePath;
            if (is_dir($fullPath)) {
                $directoryToScan = $fullPath;
            }
        }

        // Si no tenemos path o no existe, intentar buscar (fallback) con CACHÉ
        if (! $directoryToScan) {
            
            // 1. Check if we already found the path for this product code
            if (isset($this->pathCache[$productCode])) {
                $directoryToScan = $this->pathCache[$productCode];
            } else {
                // 2. If not in cache, check if we have scanned this prefix yet
                $prefix = substr($productCode, 0, 2); // E.g. A9 from A9000...
                
                if (! isset($this->prefixScanned[$prefix])) {
                    // Log::info("ProductImageService: Scanning file system for prefix {$prefix}...");
                    
                    // Scan all folders for this prefix to populate cache
                    // Pattern: Base / * / Prefix / ProductCode
                    $pattern = "{$basePath}/*/{$prefix}/*";
                    $foundDirs = glob($pattern, GLOB_ONLYDIR);
                    
                    if ($foundDirs) {
                        foreach ($foundDirs as $dir) {
                            $code = basename($dir);
                            $this->pathCache[$code] = $dir;
                        }
                    }
                    
                    // Mark prefix as scanned so we don't glob again even if code not found
                    $this->prefixScanned[$prefix] = true;
                }
                
                // 3. Check cache again after scan
                if (isset($this->pathCache[$productCode])) {
                    $directoryToScan = $this->pathCache[$productCode];
                } else {
                     // Last resort: deep search? Or assume it's missing.
                     // The original "deep search" was glob("{$basePath}/*/*/{$productCode}")
                     // We can try that IF the prefix structure assumption (Base/*/{Prefix}) is wrong.
                     // But for now let's rely on the prefix scan.
                     // If we want to accept the 0.5s penalty for truly missing items, we can keep the deep fallback here.
                     // But to solve user's "slow" issue, we should avoid it.
                }
            }
        }

        if (! $directoryToScan) {
            return [];
        }

        // Escanear el directorio encontrado
        $files = scandir($directoryToScan);
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        $images = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, $allowedExtensions)) {
                $images[] = $file;
            }
        }

        return $images;
    }

    /**
     * Generar URL pública para una imagen
     */
    public function getImageUrl(string $productCode, string $filename): string
    {
        // return url("/api/product-images/{$productCode}/".rawurlencode($filename));
        return "https://apycloudti.com/api/product-images/{$productCode}/".rawurlencode($filename);
    }

    /**
     * Obtener URLs en formato WooCommerce
     * Retorna array de objetos con 'src'
     */
    public function getWooCommerceImageUrls(?string $ftpPath, bool $verify = true): array
    {
        // Si no hay ruta FTP, retornar vacío
        if (empty($ftpPath)) {
            return [];
        }

        // Extraer código de producto
        $productCode = $this->extractProductCode($ftpPath);

        if (empty($productCode)) {
            return [];
        }

        // Normalizar path para búsqueda directa
        $normalizedPath = $this->normalizeFtpPath($ftpPath);

        // Obtener lista de imágenes usando el path específico
        $images = $this->getProductImages($productCode, $normalizedPath);

        // Opcional: Fallback REMOVED as per requirement
        // if (empty($images)) { ... }
        // Opcional

        // Convertir a formato WooCommerce
        return array_map(function ($filename) use ($productCode) {
            return [
                'src' => $this->getImageUrl($productCode, $filename),
            ];
        }, $images);
    }

    /**
     * Verificar si la imagen existe en la URL remota
     */
    public function verifyImageExists(string $url): bool
    {
        try {
            return Http::timeout(5)->head($url)->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
