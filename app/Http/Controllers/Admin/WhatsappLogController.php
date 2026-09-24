<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
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

        // «general» = ما خرج من البوابة العامة، ورقمٌ = ما خرج من رقم قسمٍ بعينه.
        $account = $request->string('account')->toString();
        $accountId = ctype_digit($account) ? (int) $account : null;

        $query = WhatsappMessage::query()
            ->with('account:id,name')
            ->when($accountId, fn ($q) => $q->where('whatsapp_account_id', $accountId))
            ->when($account === 'general', fn ($q) => $q->whereNull('whatsapp_account_id'))
            ->when($request->string('purpose')->toString(), fn ($q, $p) => $q->where('purpose', $p))
            ->when($request->string('category')->toString(), fn ($q, $c) => $q->where('category', $c))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        $conversations = WhatsappMessage::conversationCount($from, $to, $accountId);

        return Inertia::render('admin/contracts/WhatsappLog', [
            'messages' => (clone $query)->latest('id')->paginate(30)->withQueryString()
                ->through(fn (WhatsappMessage $m) => [
                    'id' => $m->id,
                    'to_number' => $m->to_number,
                    'body' => $m->body,
                    'purpose' => $m->purpose,
                    'purpose_label' => $m->purposeLabel(),
                    'category' => $m->category,
                    'category_label' => $m->categoryLabel(),
                    'status' => $m->status,
                    'account' => $m->account?->name,
                    'error' => $m->error,
                    'created_at' => $m->created_at->format('Y-m-d H:i'),
                ]),
            'filters' => ['from' => $from, 'to' => $to] + $request->only(['purpose', 'category', 'status', 'account']),
            'accounts' => WhatsappAccount::ordered()->get(['id', 'name'])
                ->map(fn (WhatsappAccount $a) => ['key' => (string) $a->id, 'label' => $a->name])->values(),
            'purposes' => collect(WhatsappMessage::PURPOSES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'categories' => collect(WhatsappMessage::CATEGORIES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'stats' => [
                'messages' => (clone $query)->count(),
                'sent' => (clone $query)->where('status', 'sent')->count(),
                'failed' => (clone $query)->where('status', 'failed')->count(),
                'queued' => (clone $query)->where('status', 'queued')->count(),
                'marketing' => (clone $query)->where('category', 'marketing')->count(),
                // المحادثة (24 ساعة) هي وحدة التسعير لدى Meta لا الرسالة
                'conversations' => $conversations,
                'limit' => $limit,
                'usage_percent' => $limit > 0 ? round($conversations / $limit * 100, 1) : 0.0,
                'warn_at' => (int) config('operations.whatsapp.warn_at_percent', 80),
            ],
        ]);
    }
}
