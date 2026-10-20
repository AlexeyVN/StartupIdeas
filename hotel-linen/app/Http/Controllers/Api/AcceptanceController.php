<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Room;
use App\Services\AcceptanceService;
use Illuminate\Http\Request;

class AcceptanceController extends Controller
{
    /** POST /api/rooms/{room}/checkout-acceptance — запуск приёмки (вручную или из PMS) */
    public function start(Room $room)
    {
        $doc = AcceptanceService::startCheckoutAcceptance($room);
        return response()->json(['doc_id' => $doc->id, 'checklist' => $doc->checklists]);
    }

    /** PUT /api/acceptance/{doc} — чек-лист + расхождения + фото; результат — акт с подписью */
    public function finish(Request $r, Document $doc)
    {
        $v = $r->validate([
            'checklist' => 'present|array',
            'checklist.*.group' => 'required|string', 'checklist.*.text' => 'required|string',
            'checklist.*.passed' => 'boolean', 'checklist.*.comment' => 'nullable|string',
            'discrepancies' => 'present|array',
            'discrepancies.*.item_id' => 'required|exists:items,id',
            'discrepancies.*.qty' => 'required|numeric|min:1',
            'discrepancies.*.kind' => 'required|in:loss,damage',
            'discrepancies.*.reason_code' => 'required|string|exists:reasons,code',
            'photos.*' => 'nullable|image|max:5120',
        ]);
        $out = AcceptanceService::finishCheckoutAcceptance($doc, $v['checklist'], $v['discrepancies'], $r->file('photos', []));
        return response()->json(['ok' => true, 'doc_number' => $out->number, 'room_status' => $out->room->fresh()->status]);
    }

    /** POST /api/rooms/{room}/readiness — проверка перед заездом (супервайзер/менеджер) */
    public function readiness(Request $r, Room $room)
    {
        $v = $r->validate([
            'sample' => 'boolean',
            'checklist' => 'required|array',
            'checklist.*.group' => 'required|string', 'checklist.*.text' => 'required|string',
            'checklist.*.passed' => 'boolean', 'checklist.*.comment' => 'nullable|string',
        ]);
        $doc = AcceptanceService::runReadinessCheck($room, $v['checklist'], (bool)($v['sample'] ?? false));
        return response()->json([
            'result' => $doc->room->fresh()->status,
            'doc_number' => $doc->number,
            'blocked_reason' => $doc->meta['blocked_reason'] ?? null,
        ]);
    }

    /** PATCH /api/rooms/{room}/status — переход DIRTY→CLEANING→INSPECTION→READY по ролям */
    public function status(Request $r, Room $room)
    {
        $v = $r->validate(['to' => 'required|in:DIRTY,CLEANING,INSPECTION,READY,OOO', 'reason' => 'nullable|string']);
        try {
            AcceptanceService::setStatus($room, $v['to'], $v['reason'] ?? '');
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['ok' => true, 'status' => $room->fresh()->status]);
    }
}
