<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ProductImageService
{
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
        $basePath = storage_path('app/private/ftp_sync');
        $directoryToScan = null;

        // Si tenemos el path relativo (desde Home), usarlo directamente
        if ($relativePath) {
            $fullPath = $basePath . '/' . $relativePath;
            if (is_dir($fullPath)) {
                $directoryToScan = $fullPath;
            }
        }
        
        // Si no tenemos path o no existe, intentar buscar (fallback)
        if (!$directoryToScan) {
            // Patrón optimizado: P/A9/A90000000130001
            // Asumimos que la estructura siempre respeta los primeros 2 digitos del código tras P/
            $prefix = substr($productCode, 0, 2);
            $pattern = "{$basePath}/*/{$prefix}/{$productCode}";
            
            $dirs = glob($pattern, GLOB_ONLYDIR);
            
            if (!empty($dirs)) {
                $directoryToScan = $dirs[0];
            } else {
                // Fallback final: búsqueda profunda (lenta)
                $dirs = glob("{$basePath}/*/*/{$productCode}", GLOB_ONLYDIR);
                if (!empty($dirs)) {
                    $directoryToScan = $dirs[0];
                }
            }
        }

        if (!$directoryToScan) {
            return [];
        }

        // Escanear el directorio encontrado
        $files = scandir($directoryToScan);
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'svg', 'jfif'];
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
        return url("/api/product-images/{$productCode}/" . rawurlencode($filename));
    }

    /**
     * Obtener URLs en formato WooCommerce
     * Retorna array de objetos con 'src'
     */
    public function getWooCommerceImageUrls(?string $ftpPath): array
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

        // Convertir a formato WooCommerce
        return array_map(function($filename) use ($productCode) {
            return [
                'src' => $this->getImageUrl($productCode, $filename)
            ];
        }, $images);
    }
}
