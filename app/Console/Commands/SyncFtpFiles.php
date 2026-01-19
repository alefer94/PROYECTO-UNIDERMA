<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Exception;

class SyncFtpFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ftp:sync {--force : Force re-download all files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Intelligently sync files from FTP (only downloads new/modified files)';

    protected $syncIndexPath = 'ftp_sync/.sync_index.json';
    protected $syncIndex = [];
    protected $ftpFiles = [];
    protected $stats = [
        'downloaded' => 0,
        'skipped' => 0,
        'failed' => 0,
        'orphaned' => 0,
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Smart FTP Sync...');
        
        // Load sync index
        $this->loadSyncIndex();
        
        // Start recursive sync
        $this->info('Scanning FTP server (recursive)...');
        $this->syncDirectory('/');
        
        // Detect orphaned files
        $this->detectOrphanedFiles();
        
        // Save updated index
        $this->saveSyncIndex();
        
        // Display summary
        $this->displaySummary();
    }

    protected function loadSyncIndex()
    {
        $localDisk = Storage::disk('local');
        
        if ($localDisk->exists($this->syncIndexPath)) {
            $json = $localDisk->get($this->syncIndexPath);
            $this->syncIndex = json_decode($json, true) ?? [];
            $this->info('Loaded sync index with ' . count($this->syncIndex) . ' tracked files.');
        } else {
            $this->syncIndex = [];
            $this->info('No sync index found. This is a fresh sync.');
        }
    }

    protected function saveSyncIndex()
    {
        $localDisk = Storage::disk('local');
        $localDisk->put($this->syncIndexPath, json_encode($this->syncIndex, JSON_PRETTY_PRINT));
        $this->info('Sync index saved.');
    }

    protected function syncDirectory($directory)
    {
        $this->line("Scanning: {$directory}");
        
        $retries = 3;
        $files = [];
        $directories = [];

        while ($retries > 0) {
            try {
                // Use listContents to get BOTH files and directories in ONE operation
                $contents = Storage::disk('ftp_erp')->listContents($directory, false);
                
                // Separate files from directories, store metadata for files
                foreach ($contents as $item) {
                    if ($item['type'] === 'file') {
                        $files[] = [
                            'path' => $item['path'],
                            'size' => $item['size'] ?? 0,
                            'timestamp' => $item['timestamp'] ?? time(),
                        ];
                    } elseif ($item['type'] === 'dir') {
                        $directories[] = $item['path'];
                    }
                }
                
                break;
            } catch (Exception $e) {
                $retries--;
                if ($retries === 0) {
                    $this->error("Failed to list {$directory} after 3 attempts: " . $e->getMessage());
                    return;
                }
                
                $this->warn("  ! Connection unstable. Retrying in 2s... ({$retries} left)");
                sleep(2);
                Storage::forgetDisk('ftp_erp');
            }
        }

        $localDisk = Storage::disk('local');

        // Process files
        foreach ($files as $fileInfo) {
            $this->ftpFiles[] = $fileInfo['path']; // Track all FTP files for orphan detection
            $this->downloadFileIfNeeded($fileInfo, $localDisk);
        }

        // Recurse into directories
        foreach ($directories as $dir) {
            $this->syncDirectory($dir);
        }
    }

    protected function downloadFileIfNeeded($fileInfo, $localDisk)
    {
        $filePath = $fileInfo['path'];
        $ftpSize = $fileInfo['size'];
        $ftpTimestamp = $fileInfo['timestamp'];
        
        // Sanitize filename: Replace spaces with underscores
        $dir = dirname($filePath);
        $filename = basename($filePath);
        $sanitizedFilename = str_replace(' ', '_', $filename);
        $sanitizedPath = ($dir === '.' ? '' : $dir . '/') . $sanitizedFilename;
        
        $localPath = "ftp_sync/{$sanitizedPath}";
        
        // Check if we should skip this file (exists locally AND size matches)
        if (!$this->option('force') && $localDisk->exists($localPath)) {
            // Compare with sync index to detect changes
            if (isset($this->syncIndex[$filePath])) {
                $indexed = $this->syncIndex[$filePath];
                
                // If size matches, skip download
                if (isset($indexed['size']) && $indexed['size'] === $ftpSize) {
                    $this->stats['skipped']++;
                    return;
                }
            }
        }

        $this->line("  ⬇ Downloading modified file: {$filePath}");
        
        $retries = 3;
        
        while ($retries > 0) {
            try {
                // Ensure directory exists locally
                if (!$localDisk->exists(dirname($localPath))) {
                    $localDisk->makeDirectory(dirname($localPath));
                }

                // Stream download to save memory
                $stream = Storage::disk('ftp_erp')->readStream($filePath);
                
                if ($stream === false || !is_resource($stream)) {
                    throw new Exception("Could not open stream for {$filePath}");
                }
                
                $localDisk->put($localPath, $stream);
                
                if (is_resource($stream)) {
                    fclose($stream);
                }

                // Update index
                $this->syncIndex[$filePath] = [
                    'size' => $ftpSize,
                    'timestamp' => $ftpTimestamp,
                    'synced_at' => time(),
                ];
                
                $this->stats['downloaded']++;
                break; // Success!

            } catch (Exception $e) {
                $retries--;
                
                if ($retries === 0) {
                    $this->error("  ✗ Failed to download {$filePath}: " . $e->getMessage());
                    $this->stats['failed']++;
                    return;
                }
                
                $this->warn("  ⚠ Connection dropped. Reconnecting... ({$retries} attempts left)");
                
                // Force reconnection
                Storage::forgetDisk('ftp_erp');
                sleep(2);
            }
        }
    }

    protected function shouldSkipFile($filePath, $ftpSize, $ftpTimestamp)
    {
        // If not in index, must download
        if (!isset($this->syncIndex[$filePath])) {
            return false;
        }
        
        $indexed = $this->syncIndex[$filePath];
        
        // Compare size and timestamp
        if ($indexed['size'] === $ftpSize && $indexed['timestamp'] === $ftpTimestamp) {
            return true; // File unchanged, skip
        }
        
        return false; // File changed, download
    }

    protected function detectOrphanedFiles()
    {
        $this->info('Detecting orphaned files...');
        
        $localDisk = Storage::disk('local');
        
        if (!$localDisk->exists('ftp_sync')) {
            return;
        }
        
        // 1. Build a set of "valid" local paths from the FTP list
        // logic: if FTP has "foo bar.jpg", valid local is "foo_bar.jpg"
        $validLocalPaths = [];
        foreach (array_keys($this->syncIndex) as $ftpPath) { // syncIndex keys are the FTP paths
             $dir = dirname($ftpPath);
             $filename = basename($ftpPath);
             $sanitized = str_replace(' ', '_', $filename);
             $path = ($dir === '.' ? '' : $dir . '/') . $sanitized;
             
             // Normalize slashes just in case
             $validLocalPaths[] = str_replace('\\', '/', $path);
        }
        
        // Add index file as valid
        $validLocalPaths[] = '.sync_index.json';

        // 2. Get all actual local files
        $localFiles = $localDisk->allFiles('ftp_sync');
        $orphans = [];
        
        foreach ($localFiles as $localFile) {
            // Remove 'ftp_sync/' prefix
            $relativePath = str_replace(['ftp_sync/', '\\'], ['', '/'], $localFile);
            
            // Check if this local file matches any valid sanitized FTP path
            if (!in_array($relativePath, $validLocalPaths)) {
                 $orphans[] = $relativePath;
            }
        }
        
        if (count($orphans) > 0) {
            $this->warn("\n⚠ Found " . count($orphans) . " orphaned files (exist locally but not on FTP):");
            foreach ($orphans as $orphan) {
                $this->line("  • {$orphan}");
            }
            $this->stats['orphaned'] = count($orphans);
        } else {
            $this->info('No orphaned files found.');
        }
    }

    protected function displaySummary()
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('FTP Sync Completed!');
        $this->info('═══════════════════════════════════════');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Downloaded', $this->stats['downloaded']],
                ['Skipped (unchanged)', $this->stats['skipped']],
                ['Failed', $this->stats['failed']],
                ['Orphaned (local only)', $this->stats['orphaned']],
            ]
        );
        $this->info('Files location: ' . storage_path('app/private/ftp_sync'));
    }
}
