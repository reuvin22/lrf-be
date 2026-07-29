<?php

namespace App\Http\Controllers\v1;

use App\Events\SegmentEvent;
use App\Http\Requests\v1\SegmentRequests;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SegmentController extends SheetResourceController
{
    protected string $sheetName = 'Segments';
    protected string $idColumn  = 'segment_id';
    protected array $headers    = [
        'segment_id', 'attendance_id', 'employee_id', 'site_id',
        'segment_type', 'site_name', 'start_time', 'end_time', 'type',
    ];

    public function store(SegmentRequests $request): JsonResponse
    {
        $data               = $request->validated();
        $data['segment_id'] = (string) Str::uuid();
        $data               = $this->normalizeTimes($data);

        $this->appendRow($data);
        event(new SegmentEvent((object) $data, 'created'));

        return response()->json([
            'success' => true,
            'message' => 'Segment created successfully.',
            'data'    => $data,
        ], 201);
    }

    public function update(SegmentRequests $request, string $id): JsonResponse
    {
        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $data               = array_merge($located['data'], $this->normalizeTimes($request->validated()));
        $data['segment_id'] = $id;

        $this->updateRowAt($located['rowNumber'], $data);
        event(new SegmentEvent((object) $data, 'update'));

        return response()->json([
            'success' => true,
            'message' => 'Segment updated successfully.',
            'data'    => $data,
        ]);
    }

    public function destroy(\Illuminate\Http\Request $request, ?string $id = null): JsonResponse
    {
        if ($id === null) return $this->notFound('Id is required.');

        $located = $this->locate($id);
        if (!$located) return $this->notFound();

        $this->deleteRowAt($located['rowNumber']);
        event(new SegmentEvent((object) $located['data'], 'deleted'));

        return response()->json([
            'success' => true,
            'message' => 'Segment deleted successfully.',
        ]);
    }

    private function normalizeTimes(array $data): array
    {
        if (!empty($data['start_time'])) {
            $data['start_time'] = Carbon::parse($data['start_time'])->utc()->toIso8601String();
        }
        if (!empty($data['end_time'])) {
            $data['end_time'] = Carbon::parse($data['end_time'])->utc()->toIso8601String();
        }
        return $data;
    }
}
