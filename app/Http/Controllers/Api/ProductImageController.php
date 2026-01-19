<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductImageController extends Controller
{
    /**
     * Serve product image
     * 
     * GET /api/product-images/{code}/{filename}
     * Example: /api/product-images/A90000000130001/imagen1.jpg
     */
    public function show(string $code, string $filename): BinaryFileResponse
    {
        // 1. Validar código de producto (alfanumérico, máx 50 chars)
        if (!preg_match('/^[A-Z0-9]{1,50}$/i', $code)) {
            abort(404, 'Invalid product code');
        }

        // 2. Validar filename (anti path-traversal)
        if (str_contains($filename, '..') || 
            str_contains($filename, '/') || 
            str_contains($filename, '\\')) {
            abort(403, 'Invalid filename');
        }

        // 3. Validar extensión permitida
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowedExtensions)) {
            abort(403, 'Invalid file type');
        }

        // 4. Buscar imagen en storage
        $imagePath = $this->findImagePath($code, $filename);
        
        if (!$imagePath) {
            abort(404, 'Image not found');
        }

        // 5. Determinar MIME type
        $mimeType = $this->getMimeType($extension);

        // 6. Servir imagen con headers de caché
        return response()->file($imagePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=31536000, immutable', // 1 año
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Buscar ruta de imagen en storage
     * Busca en todos los subdirectorios que terminen con el código
     */
    protected function findImagePath(string $code, string $filename): ?string
    {
        $basePath = storage_path('app/private/ftp_sync');
        
        // Buscar recursivamente directorios que terminen con el código
        // Ejemplo: P/A9/A90000000130001
        $pattern = $basePath . '/*/' . substr($code, 0, 2) . '/' . $code . '/' . $filename;
        
        $files = glob($pattern);
        
        if (!empty($files) && file_exists($files[0])) {
            return $files[0];
        }

        // Fallback: buscar en toda la estructura
        $allFiles = glob($basePath . '/*/*/' . $code . '/' . $filename);
        
        if (!empty($allFiles) && file_exists($allFiles[0])) {
            return $allFiles[0];
        }

        return null;
    }

    /**
     * Obtener MIME type por extensión
     */
    protected function getMimeType(string $extension): string
    {
        return match($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            default => 'application/octet-stream',
        };
    }
}
