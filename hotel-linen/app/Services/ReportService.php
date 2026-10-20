<?php
namespace App\Services;

use App\Models\ActionLog;
use App\Models\Asset;
use App\Models\Document;
use App\Models\InventoryCount;
use App\Models\Item;
use App\Models\Reason;
use App\Models\Room;
use Illuminate\Support\Facades\DB;

/** Отчёты, включая «Где пропадает бельё» (п.5.8) и группировку расхождений по причинам */
class ReportService
{
    /**
     * Отчёт «Где пропадает бельё»: по позициям, причинам, этажам, периодам.
     * Источник — записи журнала discrepancy/write_off/inventory.adjust + стадии прачечной.
     */
    public static function whereLinenGoes(string $from, string $to): array
    {
        $logs = ActionLog::whereBetween('created_at', [$from, $to])
            ->whereIn('event', ['discrepancy', 'write_off', 'inventory.adjust'])
            ->whereNotNull('reason_code')->get();

        $byReason = $logs->groupBy('reason_code')->map(fn ($g) => [
            'title' => Reason::where('code', $g->first()->reason_code)?->title ?? $g->first()->reason_code,
            'count' => $g->count(),
        ]);

        // по позициям: извлекаем имя из object_label до « ×»
        $byItem = $logs->groupBy(fn ($l) => trim(preg_replace('/\s*×.*$/u', '', $l->object_label ?? '')))
            ->map->count()->sortDesc();

        // по этажам: subject Room → этаж
        $byFloor = collect();
        foreach ($logs as $l) {
            if ($l->subject_type === 'Room' && $room = Room::find($l->subject_id)) {
                $key = 'Этаж ' . $room->floor . ' (' . ($room->building?->name ?? '?') . ')';
                $byFloor[$key] = ($byFloor[$key] ?? 0) + 1;
            }
        }

        // где физически «застряло» бельё прямо сейчас: в прачечной по стадиям
        $laundry = Stock::join('locations', 'stocks.location_id', '=', 'locations.id')
            ->join('items', 'stocks.item_id', '=', 'items.id')
            ->where('locations.kind', 'laundry')
            ->select('items.name', DB::raw('sum(stocks.qty) qty'))->groupBy('items.name')->get();

        return [
            'period' => [$from, $to],
            'by_reason' => $byReason, 'by_item' => $byItem,
            'by_floor' => $byFloor, 'in_laundry_now' => $laundry,
            'unexplained' => $logs->whereNull('reason')->count(),
        ];
    }

    /** Сличительная ведомость: только расхождения утверждённого/на проверке акта */
    public static function reconciliation(Document $doc): array
    {
        return $doc->counts()->where('diff_qty', '!=', 0)->with(['item', 'reason'])->get()
            ->map(fn ($c) => [
                'item' => $c->item?->name, 'book' => $c->book_qty, 'fact' => $c->fact_qty,
                'diff' => $c->diff_qty, 'reason' => $c->reason?->title ?? 'НЕ УКАЗАНА',
            ])->all();
    }

    /** Таймлайн одной штучной единицы — ответ на «где / кто / когда» */
    public static function assetTimeline(Asset $asset): array
    {
        return $asset->movements()->with('user')->orderBy('created_at')->get()
            ->map(fn ($m) => [
                'when' => (string)$m->created_at,
                'who' => $m->user?->name . ' (' . ($m->user?->role?->title ?? '?') . ')',
                'event' => $m->event, 'from' => $m->from_label, 'to' => $m->to_label,
                'why' => $m->reason,
            ])->all();
    }

    /** Расхождения без объяснения — список для менеджера */
    public static function unexplainedDiscrepancies(): array
    {
        return InventoryCount::whereNotNull('diff_qty')->where('diff_qty', '!=', 0)
            ->whereNull('reason_id')->with('item')->get()
            ->map(fn ($c) => ['item' => $c->item?->name, 'diff' => $c->diff_qty, 'doc_id' => $c->document_id])->all();
    }
}
