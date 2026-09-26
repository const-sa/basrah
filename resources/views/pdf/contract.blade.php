{{--
    مستند العقد كما يُرسل للعميل ويُوقَّع — بتصميم عرض السعر الذي تصدره
    الجهة نفسها: رأسٌ بالجهة ومربع العقد، وبطاقتا الطرفين، ثم الموضوع
    والقيمة والشروط والتواقيع.

    كله جداول بحدود على الخلايا نفسها: mpdf لا يرسم حدود div داخل الخلايا،
    ولا يعرف flex/grid.
--}}
@php
    $navy = '#1e3a8a';
    $clientName = $contract->client?->name ?? ($data['client_name'] ?? null);
    $firstParty = ($isQuotation ? null : $unitName) ?? $issuer['business_name'];
    $title = $isQuotation
        ? 'عقد '.($data['subject'] ?? 'توريد وخدمات')
        : ($isStay ? 'عقد إيجار شاليه' : 'عقد إيجار قاعة');
@endphp
<style>
    body { font-family: cairo, sans-serif; color: #0f172a; font-size: 9.5pt; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .ltr { direction: ltr; unicode-bidi: embed; }
    .muted { color: #64748b; font-size: 8.5pt; }

    .brand-name { font-size: 13pt; font-weight: bold; color: {{ $navy }}; }

    .doc-title td { background: {{ $navy }}; color: #ffffff; text-align: center; padding: 5pt 8pt 1pt; font-size: 13pt; font-weight: bold; }
    .doc-title td.sub { padding: 0 8pt 5pt; font-size: 7.5pt; font-weight: normal; color: #c7d2fe; letter-spacing: 2pt; }
    .meta td { padding: 3pt 8pt; border-bottom: 0.5pt solid #e2e8f0; font-size: 9pt; }
    .meta .k { color: #64748b; }
    .meta .v { text-align: left; font-weight: bold; }

    .rule { border-bottom: 1.5pt solid {{ $navy }}; }

    .card-title { background: #f1f5f9; color: {{ $navy }}; font-weight: bold; padding: 4pt 8pt; border: 0.6pt solid #cbd5e1; }
    .card-body { padding: 5pt 8pt; border: 0.6pt solid #cbd5e1; border-top: none; font-size: 9pt; }

    h2.section { font-size: 10.5pt; font-weight: bold; color: {{ $navy }}; margin: 12pt 0 5pt;
                 border-bottom: 0.6pt solid #cbd5e1; padding-bottom: 2pt; }

    .rows td { padding: 4pt 8pt; border: 0.6pt solid #cbd5e1; font-size: 9.5pt; }
    .rows td.k { background: #f8fafc; color: #475569; font-weight: bold; width: 24%; white-space: nowrap; }

    .items th { background: {{ $navy }}; color: #ffffff; padding: 5pt; font-size: 9pt; font-weight: bold; text-align: center; border: 0.6pt solid {{ $navy }}; }
    .items td { padding: 5pt; border: 0.6pt solid #cbd5e1; font-size: 9pt; }
    .items tr.alt td { background: #f8fafc; }
    .c { text-align: center; }

    .money td { width: 33.33%; padding: 6pt; border: 0.6pt solid #cbd5e1; text-align: center; }
    .money .label { font-size: 8.5pt; color: #475569; }
    .money .value { font-size: 12pt; font-weight: bold; }

    .terms { font-size: 9pt; }

    .sign td { width: 50%; vertical-align: top; padding: 6pt 8pt; border: 0.6pt solid #cbd5e1; font-size: 9.5pt; }
    .sign td.head { background: #f1f5f9; color: {{ $navy }}; font-weight: bold; padding: 4pt 8pt; }
</style>

{{-- ── الرأس: الجهة المُصدِرة يمينًا، ومربع العقد يسارًا ── --}}
<table>
    <tr>
        <td style="width: 58%;">
            <table>
                <tr>
                    @if ($logoPath)
                        <td style="width: 30%; vertical-align: middle;">
                            <img src="{{ $logoPath }}" style="max-height: 62pt; max-width: 100%;" alt="">
                        </td>
                    @endif
                    <td style="vertical-align: middle; padding-right: 6pt;">
                        <div class="brand-name">{{ $firstParty }}</div>
                        @if ($firstParty !== $issuer['business_name'])<div class="muted">{{ $issuer['business_name'] }}</div>@endif
                        @if ($issuer['address'])<div class="muted">{{ $issuer['address'] }}</div>@endif
                        @if ($issuer['phone'])<div class="muted">هاتف: {{ $issuer['phone'] }}</div>@endif
                        @if ($issuer['tax_number'])<div class="muted">الرقم الضريبي: {{ $issuer['tax_number'] }}</div>@endif
                        @if ($issuer['commercial_register'])<div class="muted">السجل التجاري: {{ $issuer['commercial_register'] }}</div>@endif
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 4%;"></td>
        <td style="width: 38%;">
            <table class="doc-title">
                <tr><td>{{ $title }}</td></tr>
                <tr><td class="sub">CONTRACT</td></tr>
            </table>
            <table class="meta">
                <tr><td class="k">رقم العقد</td><td class="v">{{ $contract->number }}</td></tr>
                <tr>
                    <td class="k">تاريخ العقد</td>
                    <td class="v">{{ $data['contract_date'] ?? $contract->created_at?->toDateString() }}</td>
                </tr>
                @if ($isQuotation)
                    <tr><td class="k">عرض السعر</td><td class="v">{{ $data['quotation_number'] ?? $contract->quotation?->number ?? '—' }}</td></tr>
                @else
                    <tr><td class="k">رقم الحجز</td><td class="v">{{ $data['booking_reference'] ?? $contract->booking?->reference ?? '—' }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<table style="margin: 10pt 0;"><tr><td class="rule"></td></tr></table>

{{-- ── الطرفان ── --}}
<table>
    <tr>
        <td style="width: 49%;">
            <table>
                <tr><td class="card-title">الطرف الأول</td></tr>
                <tr>
                    <td class="card-body">
                        <b>{{ $firstParty }}</b>
                        @if ($issuer['phone'])<br><span class="muted">الجوال:</span> <span class="ltr">{{ $issuer['phone'] }}</span>@endif
                        @if ($issuer['address'])<br><span class="muted">العنوان:</span> {{ $issuer['address'] }}@endif
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 2%;"></td>
        <td style="width: 49%;">
            <table>
                <tr><td class="card-title">الطرف الثاني</td></tr>
                <tr>
                    <td class="card-body">
                        <b>{{ $clientName ?? '—' }}</b>
                        <br><span class="muted">الجوال:</span> <span class="ltr">{{ $contract->client?->mobile ?? ($data['client_mobile'] ?? '—') }}</span>
                        <br><span class="muted">رقم الهوية:</span> <span class="ltr">{{ $data['client_id_number'] ?? '—' }}</span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ── أولًا: موضوع العقد ── --}}
@if ($isQuotation)
    {{-- بنود العرض هي نطاق العمل المتفق عليه --}}
    <h2 class="section">أولاً: موضوع العقد ونطاق العمل</h2>
    <p style="margin: 0 0 6pt;">
        يتعهد الطرف الأول بتنفيذ أعمال <b>{{ $data['subject'] ?? 'التوريد والخدمات' }}</b> للطرف الثاني
        وفق البنود والأسعار المبيّنة أدناه، والمحرَّرة على عرض السعر رقم
        <b class="ltr">{{ $data['quotation_number'] ?? '—' }}</b>@if (!empty($data['quotation_date']))
            بتاريخ <span class="ltr">{{ $data['quotation_date'] }}</span>@endif.
    </p>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th style="width: 46%; text-align: right;">البند</th>
                <th style="width: 14%;">الكمية</th>
                <th style="width: 17%;">سعر الوحدة</th>
                <th style="width: 17%;">الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $i => $line)
                <tr @class(['alt' => $i % 2 === 1])>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>
                        <b>{{ $line['name'] ?? '—' }}</b>
                        @if (!empty($line['code']))<div class="muted">{{ $line['code'] }}</div>@endif
                    </td>
                    <td class="c">{{ $line['quantity'] ?? '' }}</td>
                    <td class="c">{{ $line['unit_price'] ?? '' }}</td>
                    <td class="c"><b>{{ $line['total_price'] ?? '' }}</b></td>
                </tr>
            @empty
                <tr><td colspan="5" class="c muted">لا بنود</td></tr>
            @endforelse
        </tbody>
    </table>
@else
    <h2 class="section">أولاً: موضوع العقد</h2>
    <p style="margin: 0 0 6pt;">
        يتعهد الطرف الأول بتأجير @if ($isStay) شاليه @else قاعة @endif
        <b>{{ $unitName ?? '—' }}</b> للطرف الثاني
        @if ($eventName) لإقامة مناسبة <b>{{ $eventName }}</b>
        @elseif ($isStay) للإقامة
        @else لإقامة مناسبته @endif.
    </p>

    <table class="rows">
        <tr>
            <td class="k">التاريخ</td>
            <td>
                <span class="ltr">{{ $data['booking_date'] ?? '—' }}</span>
                @if (!empty($data['last_day_date']) && $data['last_day_date'] !== ($data['booking_date'] ?? null))
                    — @if ($isStay) الخروج @else حتى @endif <span class="ltr">{{ $data['last_day_date'] }}</span>
                @endif
                @if (!empty($data['days_count']) && (int) $data['days_count'] > 0)
                    ({{ $data['days_count'] }} @if ($isStay) ليلة @else يوم @endif)
                @endif
            </td>
        </tr>
        @if ($periodLabel)
            <tr>
                <td class="k">الفترة</td>
                <td>
                    {{ $periodLabel }}
                    @if (!empty($data['starts_at']) && !empty($data['ends_at']))
                        (من <span class="ltr">{{ $data['starts_at'] }}</span> إلى <span class="ltr">{{ $data['ends_at'] }}</span>)
                    @endif
                </td>
            </tr>
        @endif
        @if (!empty($data['sections']))
            <tr><td class="k">النطاق المحجوز</td><td>{{ $data['sections'] }}</td></tr>
        @endif
        @if (!empty($data['guests_count']))
            <tr><td class="k">عدد الضيوف</td><td>{{ $data['guests_count'] }}</td></tr>
        @endif
    </table>
@endif

{{-- ── ثانيًا: القيمة والدفعات ── --}}
<h2 class="section">ثانيًا: القيمة والدفعات المالية</h2>
@if ($isQuotation)
    {{-- تفصيل العرض قبل إجماليه: الخصم والضريبة جزءٌ ممّا وُقّع عليه --}}
    <table class="money" style="margin-bottom: 4pt;">
        <tr>
            <td><div class="label">المجموع قبل الخصم</div><div class="value ltr">{{ $data['subtotal'] ?? '—' }}</div></td>
            <td><div class="label">الخصم</div><div class="value ltr">{{ $data['discount_amount'] ?? '—' }}</div></td>
            <td><div class="label">الضريبة</div><div class="value ltr">{{ $data['tax_amount'] ?? '—' }}</div></td>
        </tr>
    </table>
@endif
<table class="money">
    <tr>
        <td style="background: {{ $navy }}; color: #ffffff; border-color: {{ $navy }};">
            <div class="label" style="color: #c7d2fe;">@if ($isQuotation) قيمة العقد @else قيمة الإيجار @endif</div>
            <div class="value ltr">{{ $data['total_amount'] ?? '—' }}</div>
            {{-- عقد الحجز لا يفصّل سطورًا، فتُذكر ضريبته تحت قيمته --}}
            @if (! $isQuotation && ! empty($data['is_taxable']))
                <div class="label" style="color: #c7d2fe;">ريال — شامل ضريبة {{ $data['tax_rate'] }}%: {{ $data['tax_amount'] }}</div>
            @else
                <div class="label" style="color: #c7d2fe;">ريال</div>
            @endif
        </td>
        <td>
            <div class="label">@if ($isQuotation) المدفوع @else العربون المدفوع @endif</div>
            <div class="value ltr" style="color:#047857;">{{ $data['deposit_amount'] ?? '—' }}</div>
            <div class="label">ريال</div>
        </td>
        <td>
            <div class="label">المبلغ المتبقي</div>
            <div class="value ltr" style="color:#b91c1c;">{{ $data['remaining_amount'] ?? '—' }}</div>
            <div class="label">ريال</div>
        </td>
    </tr>
</table>

{{-- ── ثالثًا: الشروط — بنودًا مرقّمة، وتمتدّ على ما تحتاجه من أوراق ── --}}
@if ($terms)
    <h2 class="section">ثالثًا: الشروط والأحكام</h2>
    <div class="terms">@include('pdf.partials.terms', ['terms' => $terms])</div>
@endif

{{-- ── التواقيع: كتلة واحدة لا تُقسَم — توقيعٌ في ورقة واسمه في أخرى لا يصلح سندًا ── --}}
<table class="sign" style="margin-top: 14pt; page-break-inside: avoid;">
    <tr>
        <td class="head">الطرف الأول</td>
        <td class="head">الطرف الثاني (العميل)</td>
    </tr>
    {{-- The height is on the cell: mpdf ignores it on a div, and a signature needs the room. --}}
    <tr>
        <td style="height: 70pt;">
            {{-- The same first party named above: the let place, where there is one. --}}
            <div><span class="muted">الاسم:</span> <b>{{ ($isQuotation ? null : $unitName) ?? $issuer['manager_name'] ?? $issuer['business_name'] }}</b></div>
            <div style="margin-top: 4pt;"><span class="muted">التوقيع:</span></div>
            <div style="margin-top: 3pt;">
                @if ($signaturePath)
                    <img src="{{ $signaturePath }}" style="max-height: 40pt;" alt="">
                @endif
                @if ($stampPath)
                    <img src="{{ $stampPath }}" style="max-height: 40pt; margin-right: 10pt;" alt="">
                @endif
            </div>
        </td>
        <td style="height: 70pt;">
            <div><span class="muted">الاسم:</span> <b>{{ $clientName ?? '—' }}</b></div>
            <div style="margin-top: 4pt;"><span class="muted">التوقيع:</span></div>
        </td>
    </tr>
</table>
