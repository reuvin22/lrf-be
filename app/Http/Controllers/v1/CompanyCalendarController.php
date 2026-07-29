<?php

namespace App\Http\Controllers\v1;

use App\Http\Requests\v1\CompanyCalendarRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class CompanyCalendarController extends SheetResourceController
{
    protected string $sheetName = 'CompanyCalendar';
    protected string $idColumn  = 'calendar_id';
    protected array $headers    = ['calendar_id', 'date', 'day_type', 'note'];

    public function store(CompanyCalendarRequest $request): JsonResponse
    {
        $data                = $request->validated();
        $data['calendar_id'] = (string) Str::uuid();

        $this->appendRow($data);

        return response()->json([
            'success' => true,
            'message' => 'Calendar entry created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(CompanyCalendarRequest $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data                = array_merge($located['data'], $request->validated());
        $data['calendar_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);

        return response()->json([
            'success' => true,
            'message' => 'Calendar entry updated successfully.',
            'data'    => $data,
        ]);
    }
}
