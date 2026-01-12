<?php

namespace App\Http\Controllers;

use App\Models\Catalog;
use App\Services\CatalogSyncService;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    /**
     * Display a listing of catalogs.
     */
    public function index(Request $request)
    {
        $query = Catalog::query();

        // Search by name
        if ($request->filled('search')) {
            $query->where('nombre', 'like', '%'.$request->search.'%')
                ->orWhere('codCatalogo', 'like', '%'.$request->search.'%');
        }

        // Filter by type
        if ($request->filled('codTipcat')) {
            $query->where('codTipcat', $request->codTipcat);
        }

        // Filter by laboratory
        if ($request->filled('codLaboratorio')) {
            $query->where('codLaboratorio', $request->codLaboratorio);
        }

        // Filter by active status
        if ($request->filled('flgActivo')) {
            $query->where('flgActivo', $request->flgActivo);
        }

        // Order by most recent
        $catalogs = $query->orderBy('created_at', 'desc')->paginate(15);

        // Get unique values for filters
        $types = Catalog::select('codTipcat')->distinct()->whereNotNull('codTipcat')->pluck('codTipcat');
        $laboratories = Catalog::select('codLaboratorio')->distinct()->whereNotNull('codLaboratorio')->pluck('codLaboratorio');

        return view('catalogs.index', compact('catalogs', 'types', 'laboratories'));
    }

    /**
     * Sync catalogs from external API.
     */
    public function sync(Request $request, CatalogSyncService $syncService)
    {
        // Build parameters from request
        $params = [];

        if ($request->filled('negocio')) {
            $params['Negocio'] = $request->negocio;
        }

        if ($request->filled('tipindex')) {
            $params['TipIndex'] = (int) $request->tipindex;
        }

        if ($request->filled('codTipcat')) {
            $params['CodTipcat'] = $request->codTipcat;
        }

        if ($request->filled('codClasificador')) {
            $params['CodClasificador'] = $request->codClasificador;
        }

        if ($request->filled('codSubclasificador')) {
            $params['CodSubclasificador'] = $request->codSubclasificador;
        }

        if ($request->filled('codCatalogo')) {
            $params['CodCatalogo'] = $request->codCatalogo;
        }

        // Execute sync
        $result = $syncService->sync($params);

        // Return JSON response for AJAX
        if ($request->ajax()) {
            return response()->json($result);
        }

        // Redirect with flash message for regular requests
        if ($result['success']) {
            return redirect()->route('catalogs.index')
                ->with('success', $result['message'])
                ->with('stats', $result['stats'] ?? null);
        } else {
            return redirect()->route('catalogs.index')
                ->with('error', $result['message']);
        }
    }
}
