<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\StockService;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function store(Request $r): \Illuminate\Http\JsonResponse
    {
        $v = $r->validate([
            'kind' => 'required|in:receipt,transfer,laundry_out,laundry_in,write_off',
            'date' => 'nullable|date', 'supplier_id' => 'nullable|exists:suppliers,id',
            'doc_external' => 'nullable|string',
            'from_location_id' => 'nullable|exists:locations,id',
            'to_location_id' => 'nullable|exists:locations,id',
            'room_id' => 'nullable|exists:rooms,id',
            'laundry_stage' => 'nullable|in:dirty,washing,drying,control,clean',
            'note' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'required|exists:items,id',
            'lines.*.qty' => 'required|numeric|min:0.01',
            'lines.*.price' => 'nullable|numeric|min:0',
            'lines.*.reason_id' => 'nullable|exists:reasons,id',
        ]);
        // списание без причины — отказ уже на валидации запроса
        if ($v['kind'] === 'write_off') {
            foreach ($v['lines'] as $i => $ln) {
                if (empty($ln['reason_id'])) {
                    return response()->json(['error' => "Строка ".($i+1).": причина списания обязательна"], 422);
                }
            }
        }
        $data = $v; unset($data['lines']);
        $doc = StockService::createDoc($v['kind'], $data, $v['lines']);
        try {
            StockService::post($doc);
        } catch (\RuntimeException $e) {
            $doc->update(['status' => 'void']);
            return response()->json(['error' => $e->getMessage(), 'doc_id' => $doc->id], 422);
        }
        return response()->json(['ok' => true, 'doc' => $doc->fresh('lines')]);
    }

    public function ledger(Request $r)
    {
        $q = Document::with(['lines.item', 'supplier'])->latest('date');
        if ($r['kind']) $q->where('kind', $r['kind']);
        if ($r['room_id']) $q->where('room_id', $r['room_id']);
        if ($r['from']) $q->whereDate('date', '>=', $r['from']);
        if ($r['to']) $q->whereDate('date', '<=', $r['to']);
        return $q->paginate(50);
    }
}
