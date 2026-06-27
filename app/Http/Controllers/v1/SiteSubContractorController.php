<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\SiteSubContractorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SiteSubContractorController extends SheetResourceController
{
    protected string $sheetName = 'SiteSubContractors';
    protected string $idColumn  = 'uuid';
    protected array $headers    = [
        'uuid', 'site_id', 'site_name', 'subcontractor_id', 'subcontractor_name', 'contract_type',
    ];

    public function store(SiteSubContractorRequest $request): JsonResponse
    {
        $data         = $this->withNames($request->validated());
        $data['uuid'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Site subcontractor created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(SiteSubContractorRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data         = array_merge($located['data'], $this->withNames($request->validated()));
        $data['uuid'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Site subcontractor updated successfully.',
            'data'    => $data,
        ]);
    }

    /**
     * Resolve the display names from their ids so the sheet's site_name /
     * subcontractor_name columns are populated for API-created rows too (the
     * Apps Script fills them for rows entered manually in the sheet).
     */
    private function withNames(array $data): array
    {
        if (!empty($data['site_id'])) {
            $data['site_name'] = $this->lookupName('ConstructionSites', 'site_id', $data['site_id'], 'site_name');
        }

        if (!empty($data['subcontractor_id'])) {
            $data['subcontractor_name'] = $this->lookupName('SubContractors', 'subcontractor_id', $data['subcontractor_id'], 'company_name');
        }

        return $data;
    }

    private function lookupName(string $tab, string $idColumn, string $idValue, string $nameColumn): string
    {
        $row = collect($this->sheet->getRowsAsAssoc($this->spreadsheetId(), $tab))
            ->firstWhere($idColumn, $idValue);

        return (string) ($row[$nameColumn] ?? '');
    }
}