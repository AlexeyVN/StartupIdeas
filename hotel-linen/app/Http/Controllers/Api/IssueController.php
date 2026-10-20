<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Заявки по номерам (п. 4.8): горничная заявляет, менеджер закрывает с комментарием */
class IssueController extends Controller
{
    public function index(Request $r)
    {
        return Issue::with(['room.building', 'reporter', 'closer'])
            ->when($r['status'], fn ($q) => $q->where('status', $r['status']))
            ->when($r['room_id'], fn ($q) => $q->where('room_id', $r['room_id']))
            ->latest()->paginate(50);
    }

    public function close(Request $r, Issue $issue)
    {
        $v = $r->validate(['comment' => 'required|string|max:2000']);
        if (!$r->user()->canDo('issues.close')) abort(403, 'Закрывать заявки может менеджер');
        $old = ['status' => $issue->status];
        $issue->update(['status' => 'closed', 'closed_by' => $r->user()->id,
                        'close_comment' => $v['comment'], 'closed_at' => now()]);
        Audit::log('issue.close', $issue, [
            'object_label' => $issue->title . ' (' . $issue->room->label() . ')',
            'old' => $old, 'new' => ['status' => 'closed'], 'reason' => $v['comment'],
        ]);
        // если это была последняя открытая заявка — снимаем флаг «проблема белья»
        if (!$issue->room->issues()->whereIn('status', ['open', 'in_progress'])->exists()) {
            $issue->room->update(['linen_problem' => false]);
        }
        return response()->json(['ok' => true]);
    }
}
