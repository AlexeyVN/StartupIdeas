<?php
namespace App\Services;

use App\Models\Asset;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\Reason;
use App\Models\Room;
use App\Models\RoomStock;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Склад и движение (п. 4.3). Каждая операция атомарна (транзакция),
 * остаток не уходит в минус, каждое изменение пишется в журнал.
 */
class StockService
{
    /** Создание документа (черновик) со строками. Проводится отдельно через post(). */
    public static function createDoc(string $kind, array $data, array $lines): Document
    {
        return DB::transaction(function () use ($kind, $data, $lines) {
            $doc = Document::create([
                'number' => Document::nextNumber($kind),
                'kind' => $kind,
                'date' => $data['date'] ?? now()->toDateString(),
                'supplier_id' => $data['supplier_id'] ?? null,
                'doc_external' => $data['doc_external'] ?? null,
                'from_location_id' => $data['from_location_id'] ?? null,
                'to_location_id' => $data['to_location_id'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'laundry_stage' => $data['laundry_stage'] ?? null,
                'status' => 'draft',
                'created_by' => auth()->id(),
                'note' => $data['note'] ?? null,
            ]);
            foreach ($lines as $ln) {
                DocumentLine::create([
                    'document_id' => $doc->id,
                    'item_id' => $ln['item_id'],
                    'qty' => $ln['qty'],
                    'price' => $ln['price'] ?? 0,
                    'reason_id' => $ln['reason_id'] ?? null,
                    'comment' => $ln['comment'] ?? null,
                ]);
            }
            Audit::log('doc.create', $doc, ['object_label' => "{$doc->number} ({$doc->kind})", 'new' => $data]);
            return $doc;
        });
    }

    /** Проведение документа: применяет движения по строкам. Атомарно. */
    public static function post(Document $doc): void
    {
        if ($doc->status === 'posted') return;
        DB::transaction(function () use ($doc) {
            $doc = Document::whereKey($doc->id)->lockForUpdate()->first();
            $doc->load('lines');
            foreach ($doc->lines as $line) {
                self::applyLine($doc, $line);
            }
            $old = $doc->status;
            $doc->update(['status' => 'posted']);
            Audit::log('doc.post', $doc, [
                'object_label' => $doc->number, 'old' => ['status' => $old], 'new' => ['status' => 'posted'],
            ]);
        });
    }

    private static function applyLine(Document $doc, DocumentLine $line): void
    {
        $qty = (float)$line->qty;
        if ($qty <= 0) throw new RuntimeException('Количество должно быть положительным');

        switch ($doc->kind) {
            case 'receipt': // приход: + на склад-получатель
                self::addStock($line->item_id, $doc->to_location_id, $qty);
                break;

            case 'transfer': // склад ↔ номер / локация ↔ локация
                if ($doc->room_id && $doc->from_location_id) {          // склад → номер (выдача)
                    self::subStock($line->item_id, $doc->from_location_id, $qty);
                    self::addRoomStock($doc->room_id, $line->item_id, $qty);
                } elseif ($doc->room_id && $doc->to_location_id) {      // номер → склад (возврат)
                    self::subRoomStock($doc->room_id, $line->item_id, $qty);
                    self::addStock($line->item_id, $doc->to_location_id, $qty);
                } elseif ($doc->from_location_id && $doc->to_location_id) {
                    self::subStock($line->item_id, $doc->from_location_id, $qty);
                    self::addStock($line->item_id, $doc->to_location_id, $qty);
                } else {
                    throw new RuntimeException('Не заданы «откуда/куда» для перемещения');
                }
                break;

            case 'laundry_out': // номер/склад → прачечная (грязное)
                if ($doc->room_id) {
                    self::subRoomStock($doc->room_id, $line->item_id, $qty);
                } else {
                    self::subStock($line->item_id, $doc->from_location_id, $qty);
                }
                self::addStock($line->item_id, self::laundryLocation()->id, $qty);
                break;

            case 'laundry_in': // обратно из прачечной (чистое) → склад/номер
                self::subStock($line->item_id, self::laundryLocation()->id, $qty);
                if ($doc->room_id) {
                    self::addRoomStock($doc->room_id, $line->item_id, $qty);
                } else {
                    self::addStock($line->item_id, $doc->to_location_id, $qty);
                }
                break;

            case 'write_off': // списание — обязательна причина!
                if (!$line->reason_id) {
                    throw new RuntimeException("Списание без причины запрещено: позиция #{$line->item_id}");
                }
                $loc = $doc->from_location_id ?: self::warehouseLocation()->id;
                self::subStock($line->item_id, $loc, $qty);
                self::addStock($line->item_id, self::discardLocation()->id, $qty);
                $reason = Reason::find($line->reason_id);
                Audit::log('write_off', $line->item, [
                    'object_label' => $line->item->name . " ×{$qty}",
                    'old' => ['stock_at' => $loc], 'new' => ['stock_at' => 'discard'],
                    'reason' => $reason?->title, 'reason_code' => $reason?->code,
                ]);
                break;

            default:
                throw new RuntimeException("Неизвестный тип документа {$doc->kind}");
        }

        Audit::log('stock.move', $doc, [
            'object_label' => "{$line->item->name} ×{$qty} ({$doc->kind})",
            'new' => ['doc' => $doc->number],
        ]);
    }

    /* ---------- примитивы остатков с запретом ухода в минус ---------- */

    public static function addStock(int $itemId, int $locId, float $qty): void
    {
        $row = Stock::firstOrCreate(['item_id' => $itemId, 'location_id' => $locId]);
        Stock::whereKey($row->id)->update([
            'qty' => DB::raw('qty + ' . $qty),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
    }

    public static function subStock(int $itemId, ?int $locId, float $qty): void
    {
        if (!$locId) throw new RuntimeException('Не указана локация-источник');
        $affected = Stock::where('item_id', $itemId)->where('location_id', $locId)
            ->where('qty', '>=', $qty)
            ->update([
                'qty' => DB::raw('qty - ' . $qty),
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
        if (!$affected) {
            $have = (float)Stock::where('item_id', $itemId)->where('location_id', $locId)->value('qty');
            throw new RuntimeException("Остаток не может уйти в минус: {$itemId}@{$locId} есть {$have}, нужно {$qty}");
        }
    }

    public static function addRoomStock(int $roomId, int $itemId, float $qty): void
    {
        $row = RoomStock::firstOrCreate(['room_id' => $roomId, 'item_id' => $itemId]);
        RoomStock::whereKey($row->id)->update(['qty' => DB::raw('qty + ' . $qty), 'updated_at' => now()]);
    }

    public static function subRoomStock(int $roomId, int $itemId, float $qty): void
    {
        $affected = RoomStock::where('room_id', $roomId)->where('item_id', $itemId)
            ->where('qty', '>=', $qty)
            ->update(['qty' => DB::raw('qty - ' . $qty), 'updated_at' => now()]);
        if (!$affected) {
            throw new RuntimeException("В номере {$roomId} нет {$qty} позиции {$itemId} — минус запрещён");
        }
    }

    /* ---------- служебные локации ---------- */

    public static function warehouseLocation(): Location
    {
        return Location::firstOrCreate(['name' => 'Склад отеля'], ['kind' => 'warehouse']);
    }
    public static function laundryLocation(): Location
    {
        return Location::firstOrCreate(['name' => 'Прачечная'], ['kind' => 'laundry']);
    }
    public static function discardLocation(): Location
    {
        return Location::firstOrCreate(['name' => 'Списано'], ['kind' => 'discard']);
    }

    /** Движение штучной вещи (ТВ/фен/сейф) — по QR/инвентарному номеру */
    public static function moveAsset(string $tag, array $to, string $event, ?string $reasonCode = null, ?string $reason = null): Asset
    {
        return DB::transaction(function () use ($tag, $to, $event, $reasonCode, $reason) {
            $asset = Asset::where('asset_tag', $tag)->firstOrFail();
            $from = $asset->whereLabel();
            $asset->fill(array_filter($to))->save();
            AssetMovement::create([
                'asset_id' => $asset->id, 'user_id' => auth()->id(), 'event' => $event,
                'from_label' => $from, 'to_label' => $asset->whereLabel(),
                'reason_code' => $reasonCode, 'reason' => $reason, 'created_at' => now(),
            ]);
            Audit::log('asset.' . $event, $asset, [
                'object_label' => $asset->asset_tag . ' (' . $asset->item->name . ')',
                'old' => ['where' => $from], 'new' => ['where' => $asset->whereLabel()],
                'reason' => $reason, 'reason_code' => $reasonCode,
            ]);
            return $asset;
        });
    }

    /** Ведомость остатков и PAR: остаток, PAR, дефицит, избыток */
    public static function parReport(): array
    {
        $roomsByTemplate = Room::whereIn('status', ['DIRTY','CLEANING','INSPECTION','READY'])
            ->selectRaw('template_id, count(*) c')->groupBy('template_id')->pluck('c', 'template_id');
        $out = [];
        foreach (Item::where('is_active', true)->with('category')->get() as $item) {
            $normTotal = 0.0;
            foreach (\App\Models\TemplateLine::where('item_id', $item->id)->get() as $tl) {
                $normTotal += (float)$tl->qty * (int)($roomsByTemplate[$tl->template_id] ?? 0);
            }
            $need = $normTotal * max(1, (float)$item->par_level);
            $onhand = $item->totalQty();
            $out[] = [
                'item' => $item->name, 'unit' => $item->unit,
                'onhand' => round($onhand, 2), 'par_need' => round($need, 2),
                'deficit' => round(max(0, $need - $onhand), 2),
                'excess' => round(max(0, $onhand - $need - (float)$item->max_qty * 0), 2),
            ];
        }
        return $out;
    }
}
