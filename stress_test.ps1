for ($i=1; $i -le 30; $i++) {
    Write-Host "`n=== RUN $i ==="
    php artisan woocommerce:sync-categories
    Start-Sleep -s 1
}
