<?php

namespace App\Http\Controllers\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\ConstructionSitesRequest;
use App\Models\ConstructionSite;
use App\Models\ConstructionSites;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ConstructionSitesController extends Controller
{
    public function index(): JsonResponse
    {
        $sites = ConstructionSites::with('subcontractors.workers')->get();

        return response()->json([
            'success' => true,
            'data' => $sites
        ]);
    }

    public function store(ConstructionSitesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['site_id'] = Str::uuid();

        $site = ConstructionSites::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Construction site created successfully.',
            'data' => $site
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $site = ConstructionSites::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $site
        ]);
    }

    public function update(ConstructionSitesRequest $request, string $id): JsonResponse
    {
        $site = ConstructionSites::findOrFail($id);

        $site->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Construction site updated successfully.',
            'data' => $site
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $site = ConstructionSites::findOrFail($id);

        $site->delete();

        return response()->json([
            'success' => true,
            'message' => 'Construction site deleted successfully.'
        ]);
    }
}
