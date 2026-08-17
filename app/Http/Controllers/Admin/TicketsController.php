<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class TicketsController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->get('q', ''));
        $status = $request->get('status', '');

        $tickets = Ticket::query()
            ->with('client:id,name')
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%"));
            }))
            ->when(in_array($status, ['open', 'closed'], true), fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Ticket $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'title' => $t->title,
                'content' => $t->content,
                'client_id' => $t->client_id,
                'client_name' => $t->client?->name,
                'attachment_url' => $t->attachment_path ? Storage::url($t->attachment_path) : null,
                'status' => $t->status,
                'closed_at' => $t->closed_at?->toDateTimeString(),
                'created_at' => $t->created_at?->toDateTimeString(),
            ]);

        $stats = [
            'total' => Ticket::count(),
            'open' => Ticket::where('status', 'open')->count(),
            'closed' => Ticket::where('status', 'closed')->count(),
        ];

        return Inertia::render('admin/tickets/Index', [
            'tickets' => $tickets,
            'filters' => ['q' => $search, 'status' => $status],
            'stats' => $stats,
            'clients' => Client::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'attachment' => [
                'nullable',
                'file',
                'max:5120', // 5MB
                // قائمة بيضاء صارمة بالامتدادات ونوع المحتوى الفعلي — تمنع رفع
                // ملفات تنفيذية (php/phtml) أو صفحات (html/svg) تؤدي إلى XSS/RCE.
                'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
                'mimetypes:application/pdf,image/jpeg,image/png,'.
                    'application/msword,'.
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'.
                    'application/vnd.ms-excel,'.
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        ], [
            'attachment.mimes' => 'نوع الملف غير مسموح. المسموح: PDF أو صورة أو مستند Office.',
            'attachment.mimetypes' => 'نوع الملف غير مسموح. المسموح: PDF أو صورة أو مستند Office.',
        ]);

        $ticket = Ticket::create([
            'client_id' => $data['client_id'],
            'title' => $data['title'],
            'content' => $data['content'],
            'attachment_path' => $request->hasFile('attachment')
                ? $request->file('attachment')->store('tickets', 'public')
                : null,
            'status' => 'open',
        ]);

        return back()->with('success', "تم فتح التذكرة رقم {$ticket->number}");
    }

    public function close(Ticket $ticket): RedirectResponse
    {
        if ($ticket->isOpen()) {
            $ticket->update(['status' => 'closed', 'closed_at' => now()]);
        }

        return back()->with('success', "تم إغلاق التذكرة {$ticket->number}");
    }

    public function reopen(Ticket $ticket): RedirectResponse
    {
        $ticket->update(['status' => 'open', 'closed_at' => null]);

        return back()->with('success', "تم إعادة فتح التذكرة {$ticket->number}");
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        if ($ticket->attachment_path) {
            Storage::disk('public')->delete($ticket->attachment_path);
        }

        $ticket->delete();

        return back()->with('success', 'تم حذف التذكرة');
    }
}
