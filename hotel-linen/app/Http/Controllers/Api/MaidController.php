<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChecklistResult;
use App\Models\Issue;
use App\Models\Photo;
use App\Models\Shift;
use App\Services\AcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Мобильный интерфейс горничной: только назначенные номера (п. 3) */
class MaidController extends Controller
{
    /** GET /api/my/shift — лист заданий горничной */
    public function shift(Request $r)
    {
        $maid = $r->user();
        $shift = Shift::with('rooms.room.building', 'rooms.room.template.lines.item')
            ->where('maid_id', $maid->id)
            ->when($r['date'], fn ($q) => $q->whereDate('date', $r['date']))
            ->latest('date')->first();
        if (!$shift) return response()->json(['shift' => null]);
        // офлайн-синхронизация: сервер отдаёт задачи + незакрытые заявки по номерам
        $tasks = collect($shift->rooms)->map(fn ($sr) => [
            'room_id' => $sr->room_id,
            'number' => $sr->room->number,
            'task' => $sr->task_kind,
            'status' => $sr->room->status,
            'linen_problem' => $sr->room->linen_problem,
            'open_issues' => $sr->room->issues()->whereIn('status', ['open','in_progress'])->count(),
        ]);
        return response()->json(['shift' => ['date' => $shift->date, 'period' => $shift->period], 'tasks' => $tasks]);
    }

    /** POST /api/my/rooms/{room}/status — горничная ведёт уборку (DIRTY→CLEANING, CLEANING→INSPECTION) */
    public function roomStatus(Request $r, \App\Models\Room $room)
    {
        $this->assertAssigned($r, $room);
        $v = $r->validate(['to' => 'required|in:CLEANING,INSPECTION', 'reason' => 'nullable|string']);
        try { AcceptanceService::setStatus($room, $v['to'], $v['reason'] ?? 'Уборка'); }
        catch (\RuntimeException $e) { return response()->json(['error' => $e->getMessage()], 422); }
        return response()->json(['ok' => true, 'status' => $room->fresh()->status]);
    }

    /** POST /api/my/rooms/{room}/issues — заявка о проблеме + фото */
    public function reportIssue(Request $r, \App\Models\Room $room)
    {
        $this->assertAssigned($r, $room);
        $v = $r->validate([
            'kind' => 'required|in:linen,equipment,repair,cleaning,lost_item',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'photo' => 'nullable|image|max:5120',
        ]);
        $issue = Issue::create([
            'room_id' => $room->id, 'kind' => $v['kind'], 'title' => $v['title'],
            'description' => $v['description'] ?? null, 'reported_by' => $r->user()->id,
        ]);
        if ($r->hasFile('photo')) {
            Photo::create(['document_id' => $issue->id ?: 1, 'path' => $r->file('photo')->store('photos', 'public'),
                           'caption' => 'Заявка #' . $issue->id, 'uploaded_by' => $r->user()->id]);
        }
        \App\Services\Audit::log('issue.report', $room, ['object_label' => $v['title'], 'new' => ['issue_id' => $issue->id]]);
        return response()->json(['ok' => true, 'issue_id' => $issue->id]);
    }

    /** PUT /api/my/rooms/{room}/linen-state — состояние белья и вещей в номере */
    public function linenState(Request $r, \App\Models\Room $room)
    {
        $this->assertAssigned($r, $room);
        $v = $r->validate([
            'items' => 'required|array', 'items.*.item_id' => 'required|exists:items,id',
            'items.*.qty' => 'required|numeric|min:0', 'items.*.condition' => 'nullable|string',
        ]);
        DB::transaction(function () use ($room, $v, $r) {
            foreach ($v['items'] as $it) {
                $old = (float)DB::table('room_stocks')->where('room_id', $room->id)
                    ->where('item_id', $it['item_id'])->value('qty');
                DB::table('room_stocks')->updateOrInsert(
                    ['room_id' => $room->id, 'item_id' => $it['item_id']],
                    ['qty' => $it['qty'], 'updated_at' => now()]
                );
                \App\Services\Audit::log('room_stock.update', $room, [
                    'object_label' => \App\Models\Item::find($it['item_id'])?->name,
                    'old' => ['qty' => $old], 'new' => ['qty' => $it['qty'], 'condition' => $it['condition'] ?? null],
                    'reason' => 'Отметка горничной при уборке',
                ]);
            }
        });
        return response()->json(['ok' => true]);
    }

    private function assertAssigned(Request $r, \App\Models\Room $room): void
    {
        $assigned = DB::table('shift_rooms')->join('shifts', 'shifts.id', '=', 'shift_rooms.shift_id')
            ->where('shifts.maid_id', $r->user()->id)->where('shift_rooms.room_id', $room->id)->exists();
        abort_unless($assigned || $room->maid_id === $r->user()->id, 403, 'Номер не назначен вам');
    }
}
