<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use App\Support\HallContractTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شاشة «نموذج العقد» داخل القاعات.
 *
 * تعرض نموذج العقد المعتمد وتسمح بتحريره في مكانه، بدل الدوران
 * على شاشة قوالب العقود العامة في نظام العقود.
 */
class HallContractTemplateController extends Controller
{
    public function show(): Response
    {
        $template = $this->template();

        return Inertia::render('admin/units/ContractTemplate', [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'body' => $template->body,
                'terms' => $template->terms,
                'is_default' => $template->is_default,
                'is_active' => $template->is_active,
                'contracts_count' => $template->contracts()->count(),
            ],
            'placeholders' => ContractTemplate::placeholdersForView(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'terms' => ['nullable', 'string'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $template = $this->template();
        $template->update($data);

        // قالب افتراضي واحد فقط — وجود أكثر من واحد يجعل الاختيار عشوائيًا.
        if ($template->is_default) {
            ContractTemplate::whereKeyNot($template->id)->update(['is_default' => false]);
        }

        return back()->with('success', 'تم حفظ نموذج العقد');
    }

    /**
     * إعادة النص إلى الصياغة المثبّتة في السيدر.
     */
    public function reset(): RedirectResponse
    {
        $this->template()->update([
            'body' => HallContractTemplate::BODY,
            'terms' => HallContractTemplate::TERMS,
        ]);

        return back()->with('success', 'تمت استعادة النص الأصلي للنموذج');
    }

    /**
     * سجل النموذج — يُنشأ من النص المثبّت إن لم يكن السيدر قد شُغّل بعد.
     */
    private function template(): ContractTemplate
    {
        return ContractTemplate::firstOrCreate(
            ['name' => HallContractTemplate::NAME],
            HallContractTemplate::attributes(),
        );
    }
}
