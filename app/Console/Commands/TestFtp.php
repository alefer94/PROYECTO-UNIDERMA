<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Exception;

class TestFtp extends Command
{
    protected $signature = 'test:ftp';
    protected $description = 'Test the FTP connection and output debug info';

    public function handle()
    {
        $config = config('filesystems.disks.ftp_erp');

        $this->info('Testing FTP Connection...');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Host', $config['host'] ?? 'Not Set'],
                ['Port', $config['port'] ?? 'Not Set'],
                ['Username', $config['username'] ?? 'Not Set'],
                ['Root', $config['root'] ?? 'Not Set'],
                ['Passive', $config['passive'] ? 'true' : 'false'],
                ['SSL', $config['ssl'] ? 'true' : 'false'],
                ['Timeout', $config['timeout'] ?? 'Not Set'],
            ]
        );

        $this->info('Checking network connectivity...');
        $fp = @fsockopen($config['host'], $config['port'], $errno, $errstr, 5);
        if (!$fp) {
            $this->error("Cannot connect to {$config['host']}:{$config['port']}");
            $this->error("Error: $errstr ($errno)");
            $this->warn('Is the FTP server running? Check your firewall and port settings.');
            return;
        }
        fclose($fp);
        $this->info('Network connection successful (Port is open).');

        $this->info('Testing raw FTP login (bypassing Laravel)...');
        if ($config['ssl']) {
            $this->info('  -> Using valid SSL connection (ftp_ssl_connect)');
            $conn = @ftp_ssl_connect($config['host'], $config['port'], 10);
        } else {
            $this->info('  -> Using plain connection (ftp_connect)');
            $conn = @ftp_connect($config['host'], $config['port'], 10);
        }

        if ($conn) {
            $login = @ftp_login($conn, $config['username'], $config['password']);
            if ($login) {
                $this->info('Raw FTP Login Successful! (Credentials are correct)');
                // Try to list files raw
                $list = ftp_nlist($conn, $config['root']);
                $this->info('Raw listing check: ' . (is_array($list) ? count($list) . ' items found' : 'Failed to list'));
                
                ftp_close($conn);
            } else {
                $this->error('Raw FTP Login Failed! Check your username and password.');
                // Get the last error if possible
                $e = error_get_last();
                if ($e) {
                    $this->error('PHP Error: ' . $e['message']);
                }
                ftp_close($conn);
                return;
            }
        } else {
            $this->error('Raw PHP ftp_connect failed.');
            return;
        }

        try {
            $this->info('Attempting to list files (Laravel Flysystem)...');
            $files = Storage::disk('ftp_erp')->files('/');
            $this->info('Connection Successful! Found ' . count($files) . ' files.');
        } catch (Exception $e) {
            $this->error('Connection Failed!');
            $this->error($e->getMessage());
        }
    }
}
