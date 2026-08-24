<?php

namespace App\Http\Controllers\Admin;

use App\Support\HallContractTemplate;

/** The halls' approved contract form. */
class HallContractTemplateController extends UnitContractTemplateController
{
    protected function name(): string
    {
        return HallContractTemplate::NAME;
    }

    protected function pinnedBody(): string
    {
        return HallContractTemplate::BODY;
    }

    protected function pinnedTerms(): string
    {
        return HallContractTemplate::TERMS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function pinnedAttributes(): array
    {
        return HallContractTemplate::attributes();
    }

    /**
     * @return array<string, string>
     */
    protected function screen(): array
    {
        return [
            'title' => 'نموذج العقد',
            'subtitle' => HallContractTemplate::DESCRIPTION,
            'back_href' => '/admin/units/halls',
            'back_label' => 'القاعات',
            'endpoint' => '/admin/units/contract-template',
            'edit_perm' => 'hall_contract.edit',
        ];
    }
}
