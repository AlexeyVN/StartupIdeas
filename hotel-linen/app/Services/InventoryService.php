<?php
namespace App\Services;

use App\Models\Document;
use App\Models\InventoryCount;
use App\Models\Item;
use App\Models\Reason;
use App\Models\Room;
use App\Models\RoomStock;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * П. 4.7 Инвентаризация. Акт: DRAFT → REVIEW → APPROVED.
 * Снимок учёта фиксируется при создании; при утверждении применяется
 * разница «факт − снимок». Создатель не может утвердить свой акт.
 */
class InventoryService
{
    /** Полная (склад + прачечная + номера) или по номеру. */
    public static function create(?Room $room = null): Document
    {
        return DB::transaction(function () use ($room) {
            $doc = Document::create([
                'number' => Document::nextNumber('inventory'),
                'kind' => 'inventory',
                'date' => now()->toDateString(),
                'room_id' => $room?->id,
                'status' => 'draft',
                'created_by' => auth()->id(),
                'note' => $room ? "Инвентаризация {$room->label()}" : 'Полная инвентаризация',
                'meta' => ['snapshot_at' => now()->toDateTimeString()],
            ]);
            // снимок учётного остатка на момент создания
            if ($room) {
                foreach (RoomStock::where('room_id', $room->id)->get() as $rs) {
                    InventoryCount::create([
                        'document_id' => $doc->id, 'item_id' => $rs->item_id,
                        'room_id' => $room->id, 'book_qty' => $rs->qty,
                    ]);
                }
            } else {
                foreach (Stock::with('item')->get() as $s) {
                    InventoryCount::create([
                        'document_id' => $doc->id, 'item_id' => $s->item_id,
                        'location_id' => $s->location_id, 'book_qty' => $s->qty,
                    ]);
                }
                foreach (RoomStock::get() as $rs) {
                    InventoryCount::create([
                        'document_id' => $doc->id, 'item_id' => $rs->item_id,
                        'room_id' => $rs->room_id, 'book_qty' => $rs->qty,
                    ]);
                }
            }
            Audit::log('inventory.create', $doc, ['object_label' => $doc->note]);
            return $doc;
        });
    }

    /** Внесение фактических количеств (переводит DRAFT → REVIEW) */
    public static function submitCounts(Document $doc, array $counts): Document
    {
        if (!in_array($doc->status, ['draft', 'review'])) {
            throw new RuntimeException('Утверждённый акт только для чтения; исправление — корректирующим актом');
        }
        return DB::transaction(function () use ($doc, $counts) {
            foreach ($counts as $c) {
                $row = InventoryCount::where('document_id', $doc->id)
                    ->where('item_id', $c['item_id'])
                    ->when($c['room_id'] ?? null, fn ($q, $v) => $q->where('room_id', $v))
                    ->when($c['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
                    ->first();
                if (!$row) continue;
                $diff = (float)$c['fact_qty'] - (float)$row->book_qty;
                if (abs($diff) > 0.001 && empty($c['reason_id'])) {
                    throw new RuntimeException(
                        'Расхождение без указанной причины не сохраняется: позиция #' . $row->item_id);
                }
                $row->update([
                    'fact_qty' => $c['fact_qty'], 'diff_qty' => $diff,
                    'reason_id' => $diff != 0 ? $c['reason_id'] : null,
                ]);
            }
            $old = $doc->status;
            $doc->update(['status' => 'review']);
            Audit::log('inventory.review', $doc, ['old' => ['status' => $old], 'new' => ['status' => 'review']]);
            return $doc;
        });
    }

    /** Утверждение: применяет разницу «факт − снимок». Создатель не может утвердить сам. */
    public static function approve(Document $doc): Document
    {
        if ($doc->status !== 'review') throw new RuntimeException('Утверждать можно только акт в статусе REVIEW');
        if ($doc->created_by === auth()->id()) {
            throw new RuntimeException('Создатель акта не может его утвердить');
        }
        $missing = $doc->counts()->whereNotNull('diff_qty')->where('diff_qty', '!=', 0)
            ->whereNull('reason_id')->count();
        if ($missing) throw new RuntimeException("Есть {$missing} расхождений без причины");

        return DB::transaction(function () use ($doc) {
            foreach ($doc->counts()->where('applied', false)->get() as $row) {
                $diff = (float)($row->diff_qty ?? 0);
                if (abs($diff) < 0.001) { $row->update(['applied' => true]); continue; }
                if ($row->room_id) {
                    $rs = RoomStock::firstOrCreate(['room_id' => $row->room_id, 'item_id' => $row->item_id]);
                    RoomStock::whereKey($rs->id)->update(['qty' => max(0, (float)$rs->qty + $diff)]);
                } elseif ($row->location_id) {
                    $st = Stock::firstOrCreate(['item_id' => $row->item_id, 'location_id' => $row->location_id]);
                    Stock::whereKey($st->id)->update(['qty' => max(0, (float)$st->qty + $diff)]);
                }
                $row->update(['applied' => true]);
                $reason = $row->reason ? $row->reason->title : null;
                Audit::log('inventory.adjust', $doc, [
                    'object_label' => Item::find($row->item_id)?->name . " (Δ {$diff})",
                    'reason' => $reason, 'reason_code' => $row->reason?->code,
                ]);
            }
            $doc->update(['status' => 'approved', 'approved_by' => auth()->id()]);
            Audit::log('inventory.approve', $doc, ['new' => ['status' => 'approved']]);
            return $doc;
        });
    }

    /** Возврат в DRAFT только с причиной */
    public static function reopen(Document $doc, string $reason): Document
    {
        if (!$reason) throw new RuntimeException('Возврат в DRAFT только с указанием причины');
        if ($doc->status === 'approved') throw new RuntimeException('Утверждённый акт неизменяем — создайте корректирующий акт');
        $old = $doc->status;
        $doc->update(['status' => 'draft']);
        Audit::log('inventory.reopen', $doc, ['old' => ['status' => $old], 'new' => ['status' => 'draft'], 'reason' => $reason]);
        return $doc;
    }
}
