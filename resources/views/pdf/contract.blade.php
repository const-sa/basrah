{{--
    مستند العقد كما يُرسل للعميل ويُوقَّع.

    التنسيق بجداول لا بـflex/grid: mpdf يبني الصفحة بمحرّك طباعة قديم لا
    يعرف تخطيطات CSS الحديثة، فالجدول هو ما يضمن أن تُطبع الأعمدة أعمدةً
    بدل أن تنهار فوق بعضها.
--}}
<style>
    body { font-family: xbriyaz, sans-serif; color: #0f172a; font-size: 10.5pt; line-height: 1.7; }
    .head { border-bottom: 1.2pt solid #0f172a; padding-bottom: 6pt; margin-bottom: 10pt; }
    .head h1 { font-size: 13pt; font-weight: bold; margin: 0 0 3pt; }
    .head .meta { font-size: 9pt; color: #475569; }
    table { width: 100%; border-collapse: collapse; }
    .parties td { vertical-align: top; width: 40%; padding: 0 4pt; }
    .parties td.logo { width: 20%; text-align: center; }
    .box { border: 0.6pt solid #cbd5e1; border-radius: 4pt; padding: 6pt; }
    .box h3 { margin: 0 0 4pt; font-size: 10pt; font-weight: bold; text-align: center;
              border: 0.6pt solid #cbd5e1; border-radius: 3pt; padding: 3pt; }
    .box p { margin: 0 0 2pt; font-size: 9.5pt; }
    h2.section { font-size: 11pt; font-weight: bold; margin: 10pt 0 5pt; }
    ul.terms-list { margin: 0; padding: 0 14pt 0 0; }
    ul.terms-list li { margin-bottom: 2pt; font-size: 10pt; }
    /* Priced lines of a pools contract — bordered like an invoice body, since
       that is what the client is signing against. */
    table.lines { margin: 4pt 0 6pt; }
    table.lines th { border: 0.6pt solid #94a3b8; background: #f1f5f9; padding: 3pt;
                     font-size: 9pt; font-weight: bold; text-align: center; }
    table.lines td { border: 0.6pt solid #cbd5e1; padding: 3pt; font-size: 9.5pt; }
    .money td { width: 33.33%; padding: 0 3pt; }
    .money .cell { border: 0.6pt solid #cbd5e1; border-radius: 4pt; padding: 5pt; text-align: center; }
    .money .label { font-size: 8.5pt; color: #475569; }
    .money .value { font-size: 11pt; font-weight: bold; }
    .terms { font-size: 9.5pt; line-height: 1.8; white-space: pre-wrap; }
    .sign { border-top: 1pt solid #0f172a; padding-top: 8pt; margin-top: 10pt; }
    .sign td { width: 50%; vertical-align: top; padding: 0 6pt; }
    .ltr { direction: ltr; unicode-bidi: embed; }
    .muted { color: #94a3b8; }
</style>

<div class="head">
    <table>
        <tr>
            <td style="text-align: right;">
                <h1>
                    @if ($isQuotation)
                        عقد {{ $data['subject'] ?? 'توريد وخدمات' }}
                    @else
                        @if ($isStay) عقد إيجار شاليه @else عقد إيجار قاعة @endif
                        {{ $unitName ?? $issuer['business_name'] }}
                    @endif
                    — رقم العقد (<span class="ltr">{{ $contract->number }}</span>)
                </h1>
            </td>
            <td style="text-align: left; width: 30%;" class="meta">
                تاريخ العقد — <span class="ltr">{{ $data['contract_date'] ?? $contract->created_at?->toDateString() }}</span>
            </td>
        </tr>
    </table>
</div>

{{-- الطرفان والشعار بينهما --}}
<table class="parties">
    <tr>
        <td>
            <div class="box">
                <h3>بيانات الطرف الأول</h3>
                <p><b>{{ ($isQuotation ? null : $unitName) ?? $issuer['business_name'] }}</b></p>
                @if ($issuer['address'])<p>{{ $issuer['address'] }}</p>@endif
                @if ($issuer['phone'])<p><b>جوال:</b> <span class="ltr">{{ $issuer['phone'] }}</span></p>@endif
                @if ($issuer['tax_number'])<p><b>الرقم الضريبي:</b> <span class="ltr">{{ $issuer['tax_number'] }}</span></p>@endif
            </div>
        </td>
        <td class="logo">
            @if ($logoPath)
                <img src="{{ $logoPath }}" style="max-height: 60pt; max-width: 100%;" alt="">
            @endif
        </td>
        <td>
            <div class="box">
                <h3>بيانات الطرف الثاني</h3>
                <p><b>{{ $contract->client?->name ?? ($data['client_name'] ?? '—') }}</b></p>
                <p><b>الجوال:</b> <span class="ltr">{{ $contract->client?->mobile ?? ($data['client_mobile'] ?? '—') }}</span></p>
                <p><b>رقم الهوية:</b> <span class="ltr">{{ $data['client_id_number'] ?? '—' }}</span></p>
                @if ($isQuotation)
                    <p><b>رقم عرض السعر:</b> <span class="ltr">{{ $data['quotation_number'] ?? $contract->quotation?->number ?? '—' }}</span></p>
                @else
                    <p><b>رقم الحجز:</b> <span class="ltr">{{ $data['booking_reference'] ?? $contract->booking?->reference ?? '—' }}</span></p>
                @endif
            </div>
        </td>
    </tr>
</table>

{{-- أولاً: موضوع العقد — بنود العرض هي نطاق العمل المتفق عليه --}}
@if ($isQuotation)
    <h2 class="section">أولاً: موضوع العقد ونطاق العمل</h2>
    <p style="margin: 0 0 5pt;">
        يتعهد الطرف الأول بتنفيذ أعمال <b>{{ $data['subject'] ?? 'التوريد والخدمات' }}</b> للطرف الثاني
        وفق البنود والأسعار المبيّنة أدناه، والمحرَّرة على عرض السعر رقم
        <b class="ltr">{{ $data['quotation_number'] ?? '—' }}</b>@if (!empty($data['quotation_date']))
            بتاريخ <span class="ltr">{{ $data['quotation_date'] }}</span>@endif.
    </p>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 7%;">م</th>
                <th style="width: 45%;">البند</th>
                <th style="width: 14%;">الكمية</th>
                <th style="width: 17%;">سعر الوحدة</th>
                <th style="width: 17%;">الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $i => $line)
                <tr>
                    <td style="text-align: center;">{{ $i + 1 }}</td>
                    <td>
                        <b>{{ $line['name'] ?? '—' }}</b>
                        @if (!empty($line['code']))<span class="ltr" style="color:#64748b;"> ({{ $line['code'] }})</span>@endif
                    </td>
                    <td class="ltr" style="text-align: center;">{{ $line['quantity'] ?? '' }}</td>
                    <td class="ltr" style="text-align: center;">{{ $line['unit_price'] ?? '' }}</td>
                    <td class="ltr" style="text-align: center;"><b>{{ $line['total_price'] ?? '' }}</b></td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align: center; color:#94a3b8;">لا بنود</td></tr>
            @endforelse
        </tbody>
    </table>
@else
<h2 class="section">أولاً: موضوع العقد</h2>
<p style="margin: 0 0 5pt;">
    يتعهد الطرف الأول بتأجير @if ($isStay) شاليه @else قاعة @endif
    <b>{{ $unitName ?? '—' }}</b> للطرف الثاني
    @if ($eventName) لإقامة مناسبة <b>{{ $eventName }}</b>
    @elseif ($isStay) للإقامة
    @else لإقامة مناسبته @endif.
</p>

<ul class="terms-list">
    <li>
        <b>التاريخ:</b> <span class="ltr">{{ $data['booking_date'] ?? '—' }}</span>
        @if (!empty($data['last_day_date']) && $data['last_day_date'] !== ($data['booking_date'] ?? null))
            — @if ($isStay) الخروج @else حتى @endif <span class="ltr">{{ $data['last_day_date'] }}</span>
        @endif
        @if (!empty($data['days_count']) && (int) $data['days_count'] > 0)
            ({{ $data['days_count'] }} @if ($isStay) ليلة @else يوم @endif)
        @endif
    </li>
    @if ($periodLabel)
        <li>
            <b>الفترة:</b> {{ $periodLabel }}
            @if (!empty($data['starts_at']) && !empty($data['ends_at']))
                (من <span class="ltr">{{ $data['starts_at'] }}</span> إلى <span class="ltr">{{ $data['ends_at'] }}</span>)
            @endif
        </li>
    @endif
    @if (!empty($data['sections']))<li><b>النطاق المحجوز:</b> {{ $data['sections'] }}</li>@endif
    @if (!empty($data['guests_count']))<li><b>عدد الضيوف:</b> {{ $data['guests_count'] }}</li>@endif
</ul>
@endif

{{-- ثانيًا: القيمة والدفعات --}}
<h2 class="section">ثانيًا: القيمة والدفعات المالية</h2>
@if ($isQuotation)
    {{-- تفصيل العرض قبل إجماليه: الخصم والضريبة جزءٌ ممّا وُقّع عليه --}}
    <table class="money" style="margin-bottom: 4pt;">
        <tr>
            <td><div class="cell"><div class="label">المجموع قبل الخصم</div><div class="value ltr">{{ $data['subtotal'] ?? '—' }}</div></div></td>
            <td><div class="cell"><div class="label">الخصم</div><div class="value ltr">{{ $data['discount_amount'] ?? '—' }}</div></div></td>
            <td><div class="cell"><div class="label">الضريبة</div><div class="value ltr">{{ $data['tax_amount'] ?? '—' }}</div></div></td>
        </tr>
    </table>
@endif
<table class="money">
    <tr>
        <td>
            <div class="cell">
                <div class="label">@if ($isQuotation) قيمة العقد @else قيمة الإيجار @endif</div>
                <div class="value ltr">{{ $data['total_amount'] ?? '—' }}</div>
                {{-- عقد الحجز لا يفصّل سطورًا، فتُذكر ضريبته تحت قيمته --}}
                @if (! $isQuotation && ! empty($data['is_taxable']))
                    <div class="label">ريال — شامل ضريبة {{ $data['tax_rate'] }}%: {{ $data['tax_amount'] }}</div>
                @else
                    <div class="label">ريال</div>
                @endif
            </div>
        </td>
        <td>
            <div class="cell">
                <div class="label">@if ($isQuotation) المدفوع @else العربون المدفوع @endif</div>
                <div class="value ltr" style="color:#047857;">{{ $data['deposit_amount'] ?? '—' }}</div>
                <div class="label">ريال</div>
            </div>
        </td>
        <td>
            <div class="cell">
                <div class="label">المبلغ المتبقي</div>
                <div class="value ltr" style="color:#b91c1c;">{{ $data['remaining_amount'] ?? '—' }}</div>
                <div class="label">ريال</div>
            </div>
        </td>
    </tr>
</table>

{{-- ثالثًا: الشروط — تمتدّ على ما تحتاجه من أوراق --}}
@if ($terms)
    <h2 class="section">ثالثًا: الشروط والأحكام</h2>
    <div class="terms">{{ $terms }}</div>
@endif

{{-- التواقيع: كتلة واحدة لا تُقسَم — توقيعٌ في ورقة واسمه في أخرى لا يصلح سندًا --}}
<div class="sign">
    <table>
        <tr>
            <td>
                <div><b>توقيع الطرف الأول</b></div>
                <div><b>الاسم/</b> {{ $issuer['manager_name'] ?? $issuer['business_name'] }}</div>
                <div style="margin-top: 3pt;"><b>التوقيع/</b></div>
                <div style="margin-top: 3pt;">
                    @if ($signaturePath)
                        <img src="{{ $signaturePath }}" style="max-height: 40pt;" alt="">
                    @else
                        <span class="muted">........................</span>
                    @endif
                    @if ($stampPath)
                        <img src="{{ $stampPath }}" style="max-height: 40pt; margin-right: 10pt;" alt="">
                    @endif
                </div>
            </td>
            <td>
                <div><b>توقيع الطرف الثاني (العميل)</b></div>
                <div><b>الاسم/</b> {{ $contract->client?->name ?? ($data['client_name'] ?? '—') }}</div>
                <div style="margin-top: 3pt;"><b>التوقيع/</b></div>
                <div style="margin-top: 3pt;" class="muted">........................</div>
            </td>
        </tr>
    </table>
</div>
