<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractTemplatesController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/contracts/Templates', [
            'templates' => ContractTemplate::orderByDesc('is_default')->orderBy('id')->get()
                ->map(fn (ContractTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'body' => $t->body,
                    'terms' => $t->terms,
                    'is_default' => $t->is_default,
                    'is_active' => $t->is_active,
                    'contracts_count' => $t->contracts()->count(),
                ]),
            'placeholders' => ContractTemplate::placeholdersForView(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $template = ContractTemplate::create([...$data, 'is_active' => $data['is_active'] ?? true]);
        $this->ensureSingleDefault($template);

        return back()->with('success', 'تم إضافة القالب');
    }

    public function update(Request $request, ContractTemplate $template): RedirectResponse
    {
        $template->update($this->validated($request));
        $this->ensureSingleDefault($template);

        return back()->with('success', 'تم تحديث القالب');
    }

    public function destroy(ContractTemplate $template): RedirectResponse
    {
        if ($template->contracts()->exists()) {
            return back()->with('warning', 'لا يُحذف قالب صدرت منه عقود — عطّله بدل حذفه.');
        }

        $template->delete();

        return back()->with('success', 'تم حذف القالب');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'terms' => ['nullable', 'string'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * قالب افتراضي واحد فقط — وجود أكثر من واحد يجعل الاختيار عشوائيًا.
     */
    private function ensureSingleDefault(ContractTemplate $template): void
    {
        if ($template->is_default) {
            ContractTemplate::whereKeyNot($template->id)->update(['is_default' => false]);
        }
    }
}
