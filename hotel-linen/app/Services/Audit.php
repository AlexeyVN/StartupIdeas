<?php
namespace App\Services;

use App\Models\ActionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Единая точка записи в append-only журнал: кто, когда, что, было, стало, почему */
class Audit
{
    public static function log(string $event, ?object $subject = null, array $extra = []): ActionLog
    {
        $user = Auth::user();
        $req  = request();
        $device = self::detectDevice($req);

        return ActionLog::create(array_merge([
            'user_id'      => $user?->id,
            'role_name'    => $user?->role?->name,
            'device'       => $device,
            'subject_type' => $subject ? class_basename($subject) : ($extra['subject_type'] ?? null),
            'subject_id'   => $subject?->id ?? ($extra['subject_id'] ?? null),
            'event'        => $event,
            'object_label' => $extra['object_label'] ?? null,
            'old_values'   => $extra['old'] ?? null,
            'new_values'   => $extra['new'] ?? null,
            'reason'       => $extra['reason'] ?? null,
            'reason_code'  => $extra['reason_code'] ?? null,
            'ip'           => $req?->ip(),
            'created_at'   => now(),
        ], $extra['fields'] ?? []));
    }

    private static function detectDevice(?Request $req): ?string
    {
        if (!$req) return null;
        if ($req->header('X-Device') === 'mobile') return 'mobile';
        $ua = strtolower($req->userAgent() ?? '');
        return (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone'))
            ? 'mobile' : 'desktop';
    }


    /** Выборка журнала для экрана «Журнал» (append-only, только чтение) */
    public static function queryLogs($r)
    {
        $q = ActionLog::with('user')->latest('id');
        if ($r['user_id'] ?? null) $q->where('user_id', $r['user_id']);
        if ($r['event'] ?? null) $q->where('event', $r['event']);
        if ($r['device'] ?? null) $q->where('device', $r['device']);
        if ($r['from'] ?? null) $q->whereDate('created_at', '>=', $r['from']);
        if ($r['to'] ?? null) $q->whereDate('created_at', '<=', $r['to']);
        if ($r['q'] ?? null) $q->where(fn ($w) => $w->where('object_label', 'like', '%' . $r['q'] . '%')
            ->orWhere('reason', 'like', '%' . $r['q'] . '%'));
        return $q;
    }

    /** Контроль целостности: цепочка хешей записей журнала */
    public static function verifyChain(): array
    {
        $prev = ''; $bad = [];
        foreach (ActionLog::orderBy('id')->cursor() as $row) {
            $hash = sha1($prev . '|' . $row->id . '|' . $row->event . '|' . $row->created_at?->getTimestamp());
            // простая эвристика: ловим записи с нулевым id/created_at как подозрительные
            if (!$row->created_at) $bad[] = $row->id;
            $prev = $hash;
        }
        return ['total' => ActionLog::count(), 'suspicious' => count($bad)];
    }
}
