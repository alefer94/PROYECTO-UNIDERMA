<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Catálogo de Productos</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center">
                    <h1 class="text-3xl font-bold text-gray-900">Catálogo de Productos</h1>
                    <button onclick="syncCatalogs()" id="syncBtn"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded inline-flex items-center">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                            </path>
                        </svg>
                        <span id="syncBtnText">Sincronizar</span>
                    </button>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
            <!-- Flash Messages -->
            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative"
                    role="alert">
                    <strong class="font-bold">¡Éxito!</strong>
                    <span class="block sm:inline">{{ session('success') }}</span>
                    @if (session('stats'))
                        <div class="mt-2 text-sm">
                            <p>Insertados: {{ session('stats')['inserted'] ?? 0 }}</p>
                            <p>Actualizados: {{ session('stats')['updated'] ?? 0 }}</p>
                            <p>Total: {{ session('stats')['total'] ?? 0 }}</p>
                        </div>
                    @endif
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative"
                    role="alert">
                    <strong class="font-bold">Error!</strong>
                    <span class="block sm:inline">{{ session('error') }}</span>
                </div>
            @endif

            <!-- Alert container for AJAX messages -->
            <div id="alertContainer"></div>

            <!-- Search and Filters -->
            <div class="bg-white shadow rounded-lg p-6 mb-6">
                <form method="GET" action="{{ route('catalogs.index') }}" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <!-- Search -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
                            <input type="text" name="search" value="{{ request('search') }}"
                                placeholder="Nombre o código..."
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <!-- Type Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                            <select name="codTipcat"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Todos</option>
                                @foreach ($types as $type)
                                    <option value="{{ $type }}"
                                        {{ request('codTipcat') == $type ? 'selected' : '' }}>{{ $type }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Laboratory Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Laboratorio</label>
                            <select name="codLaboratorio"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Todos</option>
                                @foreach ($laboratories as $lab)
                                    <option value="{{ $lab }}"
                                        {{ request('codLaboratorio') == $lab ? 'selected' : '' }}>{{ $lab }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Active Status Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                            <select name="flgActivo"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Todos</option>
                                <option value="1" {{ request('flgActivo') === '1' ? 'selected' : '' }}>Activo
                                </option>
                                <option value="0" {{ request('flgActivo') === '0' ? 'selected' : '' }}>Inactivo
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit"
                            class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                            Filtrar
                        </button>
                        <a href="{{ route('catalogs.index') }}"
                            class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded">
                            Limpiar
                        </a>
                    </div>
                </form>
            </div>

            <!-- Products Table -->
            <div class="bg-white shadow rounded-lg overflow-hidden">
                @if ($catalogs->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">codCatalogo</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Nombre</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Corta</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Descripción</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">codTipcat</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">codClasificador</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">codSubclasificador</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">codLaboratorio</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Registro</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Presentación</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Composición</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Beneficios</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Modo de Uso</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Contraindicaciones</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Advertencias</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Precauciones</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Tipo Receta</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Show Modo</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Precio</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Stock</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Home</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Link</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">pasCodTag</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 tracking-wider">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach ($catalogs as $catalog)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-2 whitespace-nowrap text-xs font-medium text-gray-900">{{ $catalog->codCatalogo }}</td>
                                        <td class="px-4 py-2 text-xs text-gray-900">{{ $catalog->nombre }}</td>
                                        <td class="px-4 py-2 text-xs text-gray-500">{{ $catalog->corta }}</td>
                                        <td class="px-4 py-2 text-xs text-gray-500">
                                            <div class="max-w-xs truncate" title="{{ $catalog->descripcion }}">{{ $catalog->descripcion }}</div>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500">{{ $catalog->codTipcat }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500">{{ $catalog->codClasificador }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500">{{ $catalog->codSubclasificador }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500">{{ $catalog->codLaboratorio }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500">{{ $catalog->registro }}</td>
                                        <td class="px-4 py-2 text-xs text-gray-500">{{ $catalog->presentacion }}</td>
                                        <td class="px-4 py-2 text-xs text-gray-500">
                                            <div class="max-w-xs truncate" title="{{ $catalog->composicion }}">{{ $catalog->composicion }}</div>
                                        </td>
                                        <td class="px-4 py-2 text-xs text-gray-500">
                                            <div class="max-w-xs truncate" title="{{ $catalog->bemeficios }}">{{ $catalog->bemeficios }}</div>
                                        </td>
                                        <td class="px-4 py-2 text-xs text-gray-500">
                                            <div class="max-w-xs truncate" title="{{ $catalog->modoUso }}">{{ $catalog->modoUso }}</div>
                                        </td>
                                        <td class="px-4 py-2 text-xs text-gray-500">
                                            <div class="max-w-xs truncate" title="{{ $catalog->contraindicaciones }}">{{ $catalog->contraindicaciones }}</div>
                                        </td>
                                        <td class="px-4 py-2 text-xs text-gray-500">
                                            <div class="max-w-xs truncate" title="{{ $catalog->advertencias }}">{{ $catalog->advertencias }}</div>
                                        </td>
                                        <td class="px-4 py-2 text-xs text-gray-500">
                                            <div class="max-w-xs truncate" title="{{ $catalog->precauciones }}">{{ $catalog->precauciones }}</div>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500">{{ $catalog->tipReceta }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500">{{ $catalog->showModo }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-900">S/ {{ number_format($catalog->precio, 2) }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-900">{{ $catalog->stock }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500">{{ $catalog->home }}</td>
                                        <td class="px-4 py-2 text-xs text-gray-500">
                                            @if($catalog->link)
                                                <a href="{{ $catalog->link }}" target="_blank" class="text-blue-600 hover:text-blue-800 truncate block max-w-xs">{{ $catalog->link }}</a>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-xs text-gray-500">{{ $catalog->pasCodTag }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap">
                                            @if ($catalog->flgActivo == 1)
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Activo</span>
                                            @else
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactivo</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                        {{ $catalogs->links() }}
                    </div>
                @else
                    <div class="text-center py-12">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4">
                            </path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">No hay productos</h3>
                        <p class="mt-1 text-sm text-gray-500">Comienza sincronizando el catálogo desde la API.</p>
                        <div class="mt-6">
                            <button onclick="syncCatalogs()"
                                class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                    </path>
                                </svg>
                                Sincronizar Ahora
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </main>
    </div>

    <script>
        function syncCatalogs() {
            const btn = document.getElementById('syncBtn');
            const btnText = document.getElementById('syncBtnText');
            const alertContainer = document.getElementById('alertContainer');

            // Disable button and show loading
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            btnText.textContent = 'Sincronizando...';

            // Clear previous alerts
            alertContainer.innerHTML = '';

            // Make AJAX request
            fetch('{{ route('catalogs.sync') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({})
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show success message
                        let statsHtml = '';
                        if (data.stats) {
                            statsHtml = `
                            <div class="mt-2 text-sm">
                                <p>Insertados: ${data.stats.inserted || 0}</p>
                                <p>Actualizados: ${data.stats.updated || 0}</p>
                                <p>Total: ${data.stats.total || 0}</p>
                            </div>
                        `;
                        }

                        alertContainer.innerHTML = `
                        <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                            <strong class="font-bold">¡Éxito!</strong>
                            <span class="block sm:inline">${data.message}</span>
                            ${statsHtml}
                        </div>
                    `;

                        // Reload page after 2 seconds
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    } else {
                        // Show error message
                        alertContainer.innerHTML = `
                        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                            <strong class="font-bold">Error!</strong>
                            <span class="block sm:inline">${data.message}</span>
                        </div>
                    `;

                        // Re-enable button
                        btn.disabled = false;
                        btn.classList.remove('opacity-50', 'cursor-not-allowed');
                        btnText.textContent = 'Sincronizar';
                    }
                })
                .catch(error => {
                    // Show error message
                    alertContainer.innerHTML = `
                    <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                        <strong class="font-bold">Error!</strong>
                        <span class="block sm:inline">Error de conexión: ${error.message}</span>
                    </div>
                `;

                    // Re-enable button
                    btn.disabled = false;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                    btnText.textContent = 'Sincronizar';
                });
        }
    </script>
</body>

</html>