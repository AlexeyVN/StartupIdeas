<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Document;
use App\Services\ReportService;
use App\Services\StockService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function linenLosses(Request $r)
    {
        $v = $r->validate(['from' => 'required|date', 'to' => 'required|date']);
        return response()->json(ReportService::whereLinenGoes($v['from'], $v['to']));
    }

    public function par() { return response()->json(StockService::parReport()); }

    public function reconciliation(Document $doc)
    {
        abort_unless($doc->kind === 'inventory', 404);
        return response()->json(ReportService::reconciliation($doc));
    }

    public function assetTimeline(Asset $asset)
    {
        return response()->json(ReportService::assetTimeline($asset));
    }

    /** CSV-экспорт ведомости остатков (импорт/экспорт — как в Snipe-IT) */
    public function exportPar()
    {
        $rows = StockService::parReport();
        $csv = "Позиция;Ед.;Остаток;PAR-потребность;Дефицит;Избыток\n";
        foreach ($rows as $x) {
            $csv .= "{$x['item']};{$x['unit']};{$x['onhand']};{$x['par_need']};{$x['deficit']};{$x['excess']}\n";
        }
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="par_report.csv"',
        ]);
    }
}
