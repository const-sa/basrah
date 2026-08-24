<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approved contract form of one unit activity, viewed and edited in place
 * rather than through the general contract-templates screen. Halls and chalets
 * let on different forms; only the pinned text differs between them.
 */
abstract class UnitContractTemplateController extends Controller
{
    /** The template's matching key in the database. */
    abstract protected function name(): string;

    /** The text pinned in the system, restored by the "restore original" button. */
    abstract protected function pinnedBody(): string;

    abstract protected function pinnedTerms(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function pinnedAttributes(): array;

    /**
     * The screen's own title, endpoint and way back to its activity.
     *
     * @return array<string, string>
     */
    abstract protected function screen(): array;

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
            'screen' => $this->screen(),
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

        // One default only — more than one makes the pick arbitrary.
        if ($template->is_default) {
            ContractTemplate::whereKeyNot($template->id)->update(['is_default' => false]);
        }

        return back()->with('success', 'تم حفظ نموذج العقد');
    }

    /** Put the text back to the wording pinned in the seeder. */
    public function reset(): RedirectResponse
    {
        $this->template()->update([
            'body' => $this->pinnedBody(),
            'terms' => $this->pinnedTerms(),
        ]);

        return back()->with('success', 'تمت استعادة النص الأصلي للنموذج');
    }

    /** Created from the pinned text if the seeder has never run. */
    protected function template(): ContractTemplate
    {
        return ContractTemplate::firstOrCreate(
            ['name' => $this->name()],
            $this->pinnedAttributes(),
        );
    }
}
