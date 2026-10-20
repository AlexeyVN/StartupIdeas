<?php
namespace App\Services;

use App\Models\ChecklistTemplate;
use App\Models\Document;
use App\Models\Issue;
use App\Models\Reason;
use App\Models\Room;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * П. 4.4 Приёмка номера после выезда + п. 4.5 Проверка перед заездом.
 * Расхождения создают заявку и запись о потере/повреждении с причиной.
 */
class AcceptanceService
{
    public const STAGES = ['dirty', 'washing', 'drying', 'control', 'clean'];

    /** Запуск приёмки (вручную или из PMS при выезде). */
    public static function startCheckoutAcceptance(Room $room): Document
    {
        $tpl = ChecklistTemplate::where('kind', 'checkout_acceptance')->where('is_active', true)->firstOrFail();
        return DB::transaction(function () use ($room, $tpl) {
            $doc = StockService::createDoc('acceptance', [
                'room_id' => $room->id,
                'note' => "Приёмка {$room->label()} после выезда",
                'meta' => ['checklist_template_id' => $tpl->id],
            ], []);
            foreach ($tpl->points as $p) {
                $doc->checklists()->create(['group' => $p['group'], 'text' => $p['text'], 'passed' => true]);
            }
            Audit::log('acceptance.start', $doc, ['object_label' => $room->label()]);
            return $doc;
        });
    }

    /**
     * Сохранение результата приёмки: чек-лист, фото, расхождения.
     * $discrepancies: [{item_id, qty, kind:'loss'|'damage', reason_code, comment}]
     */
    public static function finishCheckoutAcceptance(
        Document $doc, array $checklist, array $discrepancies, array $files = []
    ): Document {
        if ($doc->kind !== 'acceptance') throw new RuntimeException('Не акт приёмки');
        $room = $doc->room;

        return DB::transaction(function () use ($doc, $checklist, $discrepancies, $files, $room) {
            // чек-лист
            $doc->checklists()->delete();
            foreach ($checklist as $p) {
                $doc->checklists()->create([
                    'group' => $p['group'], 'text' => $p['text'],
                    'passed' => (bool)($p['passed'] ?? true), 'comment' => $p['comment'] ?? null,
                ]);
            }
            // фото
            foreach ($files as $f) {
                if ($f instanceof UploadedFile) {
                    $path = Storage::disk('public')->putFromReplacementStream(
                        fn () => $f->fopen(), $f->hashName(), $f->getSize() ?: 0
                    ) ? $f->store('photos', 'public') : null;
                } else {
                    $path = is_string($f) ? $f : null;
                }
                if ($path) $doc->photos()->create(['path' => $path, 'uploaded_by' => auth()->id()]);
            }
            // расхождения → заявка + причина (почему возникло расхождение — обязательно)
            foreach ($discrepancies as $d) {
                $reason = Reason::where('code', $d['reason_code'])->first()
                    ?? throw new RuntimeException("Неизвестный код причины: {$d['reason_code']}");
                Issue::create([
                    'room_id' => $room->id,
                    'kind' => $d['kind'] === 'damage' ? 'equipment' : 'linen',
                    'title' => sprintf('%s: %s ×%s', $d['kind'] === 'damage' ? 'Повреждено' : 'Отсутствует',
                                       \App\Models\Item::find($d['item_id'])?->name ?? '?', $d['qty'] ?? 1),
                    'description' => $d['comment'] ?? null,
                    'reported_by' => auth()->id(),
                ]);
                Audit::log('discrepancy', $room, [
                    'object_label' => ($d['kind'] === 'damage' ? 'Повреждение' : 'Потеря') . ': ' .
                                      (\App\Models\Item::find($d['item_id'])?->name ?? '?') . ' ×' . ($d['qty'] ?? 1),
                    'reason' => $reason->title, 'reason_code' => $reason->code,
                ]);
                $room->update(['linen_problem' => true]);
            }
            $doc->update(['status' => 'posted', 'meta' => array_merge($doc->meta ?? [], ['signed_by' => auth()->user()?->name])]);
            // номер переходит в «грязный»
            self::setStatus($room, 'DIRTY', 'Выезд гостя, приёмка выполнена');
            Audit::log('acceptance.finish', $doc, ['object_label' => $room->label(), 'new' => ['discrepancies' => count($discrepancies)]]);
            return $doc->fresh();
        });
    }

    /**
     * П. 4.5 Проверка готовности перед заездом. Статус READY невозможен при:
     * нехватке белья по шаблону, открытых заявках, непройденном чек-листе.
     */
    public static function runReadinessCheck(Room $room, array $checklist, bool $sample = false): Document
    {
        $tpl = ChecklistTemplate::where('kind', 'pre_arrival_readiness')->where('is_active', true)->firstOrFail();
        $doc = StockService::createDoc('readiness', [
            'room_id' => $room->id,
            'note' => ($sample ? 'Выборочная ' : '') . "Проверка готовности {$room->label()}",
            'meta' => ['checklist_template_id' => $tpl->id, 'sample' => $sample, 'inspector' => auth()->user()?->name],
        ], []);
        foreach ($checklist as $p) {
            $doc->checklists()->create([
                'group' => $p['group'], 'text' => $p['text'],
                'passed' => (bool)($p['passed'] ?? true), 'comment' => $p['comment'] ?? null,
            ]);
        }

        // 1) все пункты чек-листа пройдены
        $failedPoints = $doc->checklists()->where('passed', false)->count();
        // 2) нет открытых заявок
        $openIssues = $room->issues()->whereIn('status', ['open', 'in_progress'])->count();
        // 3) комплектность по шаблону: в номере достаточно белья
        $shortage = [];
        if ($room->template_id) {
            foreach ($room->template->lines as $line) {
                $have = (float)\App\Models\RoomStock::where('room_id', $room->id)
                    ->where('item_id', $line->item_id)->value('qty');
                if ($have < (float)$line->qty) {
                    $shortage[] = $line->item->name . ": нужно {$line->qty}, есть {$have}";
                }
            }
        }

        $ok = $failedPoints === 0 && $openIssues === 0 && !$shortage;
        $doc->update(['status' => $ok ? 'approved' : 'draft']);
        if ($ok) {
            self::setStatus($room, 'READY', 'Проверка готовности пройдена');
        } else {
            $room->update(['status' => 'INSPECTION']);
        }
        Audit::log('readiness.check', $doc, [
            'object_label' => $room->label(),
            'new' => ['result' => $ok ? 'READY' : 'BLOCKED', 'failed_points' => $failedPoints,
                      'open_issues' => $openIssues, 'linen_shortage' => $shortage],
            'reason' => $ok ? null : implode('; ', array_merge(
                $shortage ? ['нехватка белья: ' . implode(', ', $shortage)] : [],
                $openIssues ? ["открытые заявки: {$openIssues}"] : [],
                $failedPoints ? ["непройденный чек-лист: {$failedPoints} пунктов"] : [])),
        ]);
        return $doc->fresh()->setRelation('room', $room);
    }

    /** Переход статуса номера с проверкой ролей и порядка (п. 4.6) */
    public static function setStatus(Room $room, string $to, string $reason = ''): void
    {
        $user = auth()->user();
        $allowedByRole = match ($to) {
            'CLEANING'   => $user?->canDo('room.status.cleaning'),
            'INSPECTION' => $user?->canDo('room.status.inspection'),
            'READY'      => $user?->canDo('room.status.ready'),
            'DIRTY'      => true, // выезд/назначение уборки может ставить горничная/система
            'OOO'        => $user?->canDo('room.status.ooo'),
            default      => false,
        };
        if (!$allowedByRole) throw new RuntimeException("Роль не имеет права переводить номер в статус {$to}");
        if (!$room->canTransitionTo($to) && $to !== 'DIRTY') {
            throw new RuntimeException("Переход {$room->status} → {$to} запрещён");
        }
        $from = $room->status;
        $room->update(['status' => $to]);
        Audit::log('room.status', $room, [
            'object_label' => $room->label(), 'old' => ['status' => $from], 'new' => ['status' => $to],
            'reason' => $reason ?: null,
        ]);
    }
}
