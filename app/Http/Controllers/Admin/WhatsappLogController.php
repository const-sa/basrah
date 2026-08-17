<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * سجل رسائل واتساب واستهلاك المحادثات مقابل حد التجديد (§4.4).
 */
class WhatsappLogController extends Controller
{
    public function index(Request $request): Response
    {
        // الحد من افتراضات التشغيل (§4.4) — يُعدَّل عند اعتماد الرقم مع العميل.
        $limit = (int) config('operations.whatsapp.annual_conversation_limit', 9360);

        $from = $request->string('from')->toString() ?: now()->startOfYear()->toDateString();
        $to = $request->string('to')->toString() ?: now()->toDateString();

        $query = WhatsappMessage::query()
            ->when($request->string('purpose')->toString(), fn ($q, $p) => $q->where('purpose', $p))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        $conversations = WhatsappMessage::conversationCount($from, $to);

        return Inertia::render('admin/contracts/WhatsappLog', [
            'messages' => (clone $query)->latest('id')->paginate(30)->withQueryString()
                ->through(fn (WhatsappMessage $m) => [
                    'id' => $m->id,
                    'to_number' => $m->to_number,
                    'body' => $m->body,
                    'purpose' => $m->purpose,
                    'purpose_label' => $m->purposeLabel(),
                    'category_label' => $m->categoryLabel(),
                    'status' => $m->status,
                    'error' => $m->error,
                    'created_at' => $m->created_at->format('Y-m-d H:i'),
                ]),
            'filters' => ['from' => $from, 'to' => $to] + $request->only(['purpose', 'status']),
            'purposes' => collect(WhatsappMessage::PURPOSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'stats' => [
                'messages' => (clone $query)->count(),
                'sent' => (clone $query)->where('status', 'sent')->count(),
                'failed' => (clone $query)->where('status', 'failed')->count(),
                // المحادثة (24 ساعة) هي وحدة التسعير لدى Meta لا الرسالة
                'conversations' => $conversations,
                'limit' => $limit,
                'usage_percent' => $limit > 0 ? round($conversations / $limit * 100, 1) : 0.0,
                'warn_at' => (int) config('operations.whatsapp.warn_at_percent', 80),
            ],
        ]);
    }
}
