<?php

namespace App\Http\Controllers\Admin;

use App\Support\ChaletContractTemplate;

/** The chalets' approved contract form — the daily rental. */
class ChaletContractTemplateController extends UnitContractTemplateController
{
    protected function name(): string
    {
        return ChaletContractTemplate::NAME;
    }

    protected function pinnedBody(): string
    {
        return ChaletContractTemplate::BODY;
    }

    protected function pinnedTerms(): string
    {
        return ChaletContractTemplate::TERMS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function pinnedAttributes(): array
    {
        return ChaletContractTemplate::attributes();
    }

    /**
     * @return array<string, string>
     */
    protected function screen(): array
    {
        return [
            'title' => 'نموذج عقد الشاليهات',
            'subtitle' => ChaletContractTemplate::DESCRIPTION,
            'back_href' => '/admin/units/chalets',
            'back_label' => 'الشاليهات',
            'endpoint' => '/admin/units/chalet-contract-template',
            'edit_perm' => 'chalet_contract.edit',
        ];
    }
}
