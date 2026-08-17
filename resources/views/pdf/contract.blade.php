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
                    @if ($isStay) عقد إيجار شاليه @else عقد إيجار قاعة @endif
                    {{ $unitName ?? $issuer['business_name'] }}
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
                <p><b>{{ $unitName ?? $issuer['business_name'] }}</b></p>
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
                <p><b>رقم الحجز:</b> <span class="ltr">{{ $data['booking_reference'] ?? $contract->booking?->reference ?? '—' }}</span></p>
            </div>
        </td>
    </tr>
</table>

{{-- أولاً: موضوع العقد --}}
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

{{-- ثانيًا: القيمة والدفعات --}}
<h2 class="section">ثانيًا: القيمة والدفعات المالية</h2>
<table class="money">
    <tr>
        <td>
            <div class="cell">
                <div class="label">قيمة الإيجار</div>
                <div class="value ltr">{{ $data['total_amount'] ?? '—' }}</div>
                <div class="label">ريال</div>
            </div>
        </td>
        <td>
            <div class="cell">
                <div class="label">العربون المدفوع</div>
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
