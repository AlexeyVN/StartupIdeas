<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Room;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function start(Request $r)
    {
        $room = $r['room_id'] ? Room::findOrFail($r['room_id']) : null;
        $doc = InventoryService::create($room);
        return response()->json(['doc_id' => $doc->id, 'number' => $doc->number,
                                 'lines' => $doc->counts()->with('item')->get()]);
    }

    public function counts(Request $r, Document $doc)
    {
        $v = $r->validate([
            'counts' => 'required|array',
            'counts.*.item_id' => 'required|exists:items,id',
            'counts.*.room_id' => 'nullable|exists:rooms,id',
            'counts.*.location_id' => 'nullable|exists:locations,id',
            'counts.*.fact_qty' => 'required|numeric|min:0',
            'counts.*.reason_id' => 'nullable|exists:reasons,id',
        ]);
        try {
            $doc = InventoryService::submitCounts($doc, $v['counts']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'status' => $doc->status]);
    }

    public function approve(Document $doc)
    {
        try { $doc = InventoryService::approve($doc); }
        catch (\RuntimeException $e) { return response()->json(['error' => $e->getMessage()], 422); }
        return response()->json(['ok' => true, 'status' => 'approved']);
    }

    public function reopen(Request $r, Document $doc)
    {
        $v = $r->validate(['reason' => 'required|string']);
        try { InventoryService::reopen($doc, $v['reason']); }
        catch (\RuntimeException $e) { return response()->json(['error' => $e->getMessage()], 422); }
        return response()->json(['ok' => true, 'status' => 'draft']);
    }
}
