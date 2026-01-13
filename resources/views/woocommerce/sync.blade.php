<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sincronización WooCommerce</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .sync-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 40px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .sync-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }

        .sync-button:active {
            transform: translateY(0);
        }

        .sync-button:disabled {
            background: #ccc;
            cursor: not-allowed;
            box-shadow: none;
        }

        .status {
            margin-top: 20px;
            padding: 15px;
            border-radius: 10px;
            display: none;
        }

        .status.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .status.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .status.loading {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .results {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 14px;
        }

        .results-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 10px;
        }

        .result-item {
            padding: 10px;
            background: white;
            border-radius: 6px;
            text-align: center;
        }

        .result-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
        }

        .result-value {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🛍️ Sincronización WooCommerce</h1>
        <p class="subtitle">Sincroniza todos los productos activos del catálogo con WooCommerce</p>

        <button class="sync-button" id="syncButton" onclick="syncProducts()">
            Sincronizar Productos
        </button>

        <div class="status" id="status"></div>

        <a href="{{ route('catalogs.index') }}" class="back-link">← Volver al catálogo</a>
    </div>

    <script>
        async function syncProducts() {
            const button = document.getElementById('syncButton');
            const status = document.getElementById('status');
            
            // Disable button and show loading
            button.disabled = true;
            button.textContent = 'Sincronizando...';
            
            status.className = 'status loading';
            status.style.display = 'block';
            status.innerHTML = '<div class="spinner"></div><p>Sincronizando productos con WooCommerce...</p>';
            
            try {
                const response = await fetch('{{ route('woocommerce.sync') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    status.className = 'status success';
                    status.innerHTML = `
                        <h3>✅ Sincronización completada exitosamente</h3>
                        <div class="results">
                            <div class="results-grid">
                                <div class="result-item">
                                    <div class="result-label">Total</div>
                                    <div class="result-value">${data.results.total}</div>
                                </div>
                                <div class="result-item">
                                    <div class="result-label">Creados</div>
                                    <div class="result-value" style="color: #28a745;">${data.results.created}</div>
                                </div>
                                <div class="result-item">
                                    <div class="result-label">Actualizados</div>
                                    <div class="result-value" style="color: #17a2b8;">${data.results.updated}</div>
                                </div>
                                <div class="result-item">
                                    <div class="result-label">Fallidos</div>
                                    <div class="result-value" style="color: #dc3545;">${data.results.failed}</div>
                                </div>
                            </div>
                            ${data.results.errors.length > 0 ? `
                                <div style="margin-top: 15px;">
                                    <strong>Errores:</strong>
                                    <ul style="margin-top: 10px; padding-left: 20px;">
                                        ${data.results.errors.map(err => `
                                            <li>SKU: ${err.sku} - ${err.error}</li>
                                        `).join('')}
                                    </ul>
                                </div>
                            ` : ''}
                        </div>
                    `;
                } else {
                    status.className = 'status error';
                    status.innerHTML = `<h3>❌ Error en la sincronización</h3><p>${data.message}</p>`;
                }
            } catch (error) {
                status.className = 'status error';
                status.innerHTML = `<h3>❌ Error de conexión</h3><p>${error.message}</p>`;
            } finally {
                button.disabled = false;
                button.textContent = 'Sincronizar Productos';
            }
        }
    </script>
</body>
</html>
