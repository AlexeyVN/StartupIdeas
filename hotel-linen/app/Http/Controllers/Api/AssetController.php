<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\ReportService;
use App\Services\StockService;
use Endroid\QrCode\Builder\Builder;
use Illuminate\Http\Request;

/** QR/штрихкод этикетки и быстрое сканирование — заимствовано из Snipe-IT */
class AssetController extends Controller
{
    public function index(Request $r)
    {
        return Asset::with(['item', 'room'])->when($r['q'], fn ($q) => $q->where('asset_tag', 'like', "%{$r['q']}%"))->paginate(50);
    }

    /** Сканирование QR горничной: payload = asset_tag → карточка с таймлайном «где/кто/когда» */
    public function scan(string $tag)
    {
        $asset = Asset::where('asset_tag', $tag)->firstOrFail();
        return response()->json([
            'asset' => $asset->load(['item', 'room']),
            'where' => $asset->whereLabel(),
            'timeline' => ReportService::assetTimeline($asset),
        ]);
    }

    /** Выдача/возврат штучной единицы с историей */
    public function move(Request $r, string $tag)
    {
        $v = $r->validate([
            'room_id' => 'nullable|exists:rooms,id',
            'location_id' => 'nullable|exists:locations,id',
            'event' => 'required|in:checkout,checkin,transfer,laundry,write_off',
            'reason_code' => 'nullable|exists:reasons,code',
            'reason' => 'nullable|string',
        ]);
        if ($v['event'] === 'write_off' && empty($v['reason_code'])) {
            return response()->json(['error' => 'Причина списания обязательна'], 422);
        }
        $to = array_filter([$v['room_id'] ?? null, $v['location_id'] ?? null]);
        $patch = isset($v['room_id']) ? ['room_id' => $v['room_id'], 'location_id' => null]
               : (isset($v['location_id']) ? ['location_id' => $v['location_id'], 'room_id' => null] : []);
        if ($v['event'] === 'write_off') $patch['status'] = 'retired';
        if ($v['event'] === 'laundry') $patch['status'] = 'laundry';
        $asset = StockService::moveAsset($tag, $patch, $v['event'], $v['reason_code'] ?? null, $v['reason'] ?? null);
        return response()->json(['ok' => true, 'where' => $asset->whereLabel()]);
    }

    /** Печатная QR-табличка номера (форма 10) / этикетка вещи */
    public function qr(string $tag)
    {
        $result = Builder::create()
            ->writer(new \Endroid\QrCode\Writer\PngWriter())
            ->data("HOTELASSET:{$tag}")
            ->size(240)->margin(8)->build();
        return response($result->getString(), 200, ['Content-Type' => 'image/png']);
    }
}
