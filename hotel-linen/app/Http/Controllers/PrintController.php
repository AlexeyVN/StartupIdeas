<?php
namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Document;
use App\Models\Room;
use App\Models\Shift;
use App\Services\ReportService;
use App\Services\StockService;
use Illuminate\Http\Request;

/**
 * Печатные формы (раздел 5). Все формы открываются в браузере с кнопкой «Печать»
 * (Ctrl+P → A4). Шаблоны — resources/views/print/*.blade.php.
 */
class PrintController extends Controller
{
    /** 1. Приходная накладная: №, наименование, цена, количество, подписи */
    public function receipt(Document $doc)
    {
        abort_unless($doc->kind === 'receipt', 404);
        return view('print.receipt', ['doc' => $doc->load(['lines.item', 'supplier', 'toLocation'])]);
    }

    /** 2. Накладная перемещения: склад ↔ номер / прачечная */
    public function transfer(Document $doc)
    {
        abort_unless(in_array($doc->kind, ['transfer', 'laundry_out', 'laundry_in']), 404);
        return view('print.transfer', [
            'doc' => $doc->load(['lines.item', 'room.building', 'fromLocation', 'toLocation']),
            'stages' => \App\Services\AcceptanceService::STAGES,
        ]);
    }

    /** 3. Акт списания: порядковый номер, наименование, причины, подписи комиссии */
    public function writeOff(Document $doc)
    {
        abort_unless($doc->kind === 'write_off', 404);
        return view('print.write_off', ['doc' => $doc->load(['lines.item', 'lines.reason'])]);
    }

    /** 4. Акт приёмки номера после выезда: чек-лист, расхождения, фото-ссылки */
    public function acceptance(Document $doc)
    {
        abort_unless($doc->kind === 'acceptance', 404);
        return view('print.acceptance', [
            'doc' => $doc->load(['room.building', 'checklists', 'photos', 'creator']),
        ]);
    }

    /** 5. Чек-лист готовности к заезду: результат проверки, проверяющий */
    public function readiness(Document $doc)
    {
        abort_unless($doc->kind === 'readiness', 404);
        return view('print.readiness', ['doc' => $doc->load(['room.building', 'checklists'])]);
    }

    /** 6. Акт инвентаризации (отель / номер): учёт, факт, отклонение, причина, подписи */
    public function inventory(Document $doc)
    {
        abort_unless($doc->kind === 'inventory', 404);
        return view('print.inventory_act', [
            'doc' => $doc->load(['counts.item', 'counts.reason', 'counts.room', 'counts.location', 'approver']),
        ]);
    }

    /** 7. Сличительная ведомость: только расхождения */
    public function reconciliation(Document $doc)
    {
        abort_unless($doc->kind === 'inventory', 404);
        return view('print.reconciliation', [
            'doc' => $doc, 'rows' => ReportService::reconciliation($doc),
        ]);
    }

    /** Ведомость остатков и PAR */
    public function par()
    {
        return view('print.par', ['rows' => StockService::parReport()]);
    }

    /** 8. Отчёт «Где пропадает бельё» */
    public function linenLosses(Request $r)
    {
        $from = $r['from'] ?? now()->startOfMonth()->toDateString();
        $to = $r['to'] ?? now()->toDateString();
        return view('print.linen_losses', ['data' => ReportService::whereLinenGoes($from, $to), 'from' => $from, 'to' => $to]);
    }

    /** 9. Лист заданий горничной: номера смены и чек-листы */
    public function maidSheet(Shift $shift)
    {
        return view('print.maid_sheet', ['shift' => $shift->load(['maid', 'rooms.room.template.lines.item'])]);
    }

    /** 10. QR-табличка номера: номер и QR-ссылка */
    public function roomQr(Room $room)
    {
        $tag = 'ROOM:' . $room->id . ':' . $room->number;
        $qrUrl = url('/api/assets/' . urlencode($tag) . '/qr'); // payload совпадает со сканером вещей
        return view('print.room_qr', ['room' => $room, 'tag' => $tag,
            'qrBase64' => 'data:image/png;base64,' . base64_encode(self::pngFor("HOTELROOM:{$room->number}"))]);
    }

    private static function pngFor(string $payload): string
    {
        $result = \Endroid\QrCode\Builder\Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data($payload)->size(240)->margin(8)->build();
        return $result->getString();
    }
}
