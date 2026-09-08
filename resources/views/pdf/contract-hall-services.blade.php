{{--
    The halls' services list. Tables, not flex/grid, and every cell explicitly
    sized: mpdf's layout engine collapses modern CSS into a single column.
--}}
@php
    // A blank value stays empty — its ruled cell is the fill-in run.
    $fill = fn ($value) => filled($value) ? $value : "\u{00A0}";

    // A count prints as written — «2», not 2.00 — and words print as words.
    $qty = fn ($value) => filled($value)
        ? (is_numeric($value) ? (string) (0 + $value) : (string) $value)
        : "\u{00A0}";

    // Ruled with room to write in, so a short sheet still prints a grid.
    $rows = array_pad($lines, max(count($lines), 12), null);
@endphp

<style>
    body { font-family: xbriyaz, sans-serif; color: #16215b; font-size: 10pt; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; }
    .num { direction: ltr; unicode-bidi: embed; }

    /* Letterhead: the hall right, its emblem centre, the accountant left. */
    .head td { vertical-align: top; }
    .head .hall { font-size: 17pt; font-weight: bold; line-height: 1.2; }
    .head .tel { font-size: 12pt; font-weight: bold; }
    .head .who { font-size: 11pt; font-weight: bold; }

    /* The title in its rule, the serial beside it in red, as the pad prints. */
    .banner { margin: 6pt 0 4pt; border-top: 1.2pt solid #16215b; border-bottom: 1.2pt solid #16215b; padding: 3pt 0; }
    .banner td { vertical-align: middle; }
    .titlebox { font-size: 14pt; font-weight: bold; letter-spacing: 1pt; }
    .serial { font-size: 13pt; font-weight: bold; color: #c8102e; }

    /* One table per LINE so each label is only as wide as its own text. */
    table.ln { margin: 0; }
    table.ln td { padding: 3pt 1pt 1pt; vertical-align: bottom; font-size: 10pt; }
    table.ln td.k { font-weight: bold; padding-left: 4pt; }
    table.ln td.v { border-bottom: 0.5pt dotted #6b6b6b; }

    .boxes { margin-top: 4pt; }
    .boxes td { vertical-align: middle; font-size: 10pt; font-weight: bold; }
    .tick { border: 0.8pt solid #16215b; padding: 2pt 8pt; text-align: center; }

    .grid { margin-top: 6pt; border: 0.8pt solid #16215b; }
    .grid th { border: 0.5pt solid #16215b; background: #eef2fb; padding: 3pt; font-size: 9pt; font-weight: bold; text-align: center; }
    .grid td { border: 0.5pt solid #16215b; padding: 2.5pt 4pt; font-size: 9pt; }
    .grid td.c { text-align: center; }

    .totals { border: 0.8pt solid #16215b; border-top: 0; }
    .totals td { border: 0.5pt solid #16215b; padding: 3pt 5pt; font-size: 9.5pt; text-align: center; }
    .totals td.k { background: #eef2fb; font-weight: bold; }

    .notes .lbl { font-size: 10pt; font-weight: bold; }
    .notes-body { border: 0.5pt solid #c3cde6; padding: 4pt 6pt; margin-top: 2pt; font-size: 9pt;
                  line-height: 1.6; white-space: pre-wrap; }

    /* Signatures stay one block: a signature split from its name proves nothing. */
    .sign { margin-top: 8pt; }
    .sign td { width: 50%; vertical-align: top; padding: 0 10pt 0 0; }

    .band { margin-top: 8pt; background: #1b3a93; color: #ffffff; text-align: center;
            padding: 4pt 8pt; font-size: 9pt; font-weight: bold; }
    .addr { margin-top: 6pt; text-align: center; font-size: 9.5pt; font-weight: bold; }
</style>

<table class="head">
    <tr>
        <td style="width: 36%;">
            <div class="hall">{{ $unitName ?? $issuer['business_name'] }}</div>
            @if ($issuer['phone'])
                <div class="tel num">{{ $issuer['phone'] }}</div>
            @endif
        </td>
        <td style="width: 28%; text-align: center;">
            @if ($logoPath)
                <img src="{{ $logoPath }}" style="max-height: 58pt; max-width: 100%;" alt="">
            @endif
        </td>
        <td style="width: 36%; text-align: left;">
            @if ($issuer['whatsapp'])
                <div class="who">المحاسب</div>
                <div class="tel num">{{ $issuer['whatsapp'] }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="banner">
    <tr>
        <td style="width: 26%;"><span class="serial num">{{ $contract->number }}</span></td>
        <td style="width: 48%; text-align: center;"><span class="titlebox">عقد وقائمة الخدمات للمناسبة</span></td>
        <td style="width: 26%;"></td>
    </tr>
</table>

<table class="ln"><tr>
    <td class="k" style="width: 13%;">رقم عقد التأجير</td>
    <td class="v num" style="width: 22%;">{{ $fill($contract->booking?->reference ?? ($data['booking_reference'] ?? null)) }}</td>
    <td class="k" style="width: 6%; padding-right: 8pt;">اليوم</td>
    <td class="v" style="width: 17%;">{{ $fill($data['check_in_day'] ?? null) }}</td>
    <td class="k" style="width: 10%; padding-right: 8pt;">تاريخ العقد</td>
    <td class="v num" style="width: 17%;">{{ $fill($data['contract_date'] ?? null) }}</td>
    <td class="k" style="width: 6%; padding-right: 8pt;">الموافق</td>
    <td class="v num" style="width: 15%;">{{ $fill($data['contract_date_hijri'] ?? null) }} هـ</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 12%;">اسم المستأجر</td>
    <td class="v" style="width: 34%;">{{ $fill($contract->client?->name ?? ($data['client_name'] ?? null)) }}</td>
    <td class="k" style="width: 8%; padding-right: 8pt;">الجوال</td>
    <td class="v num" style="width: 20%;">{{ $fill($contract->client?->mobile ?? ($data['client_mobile'] ?? null)) }}</td>
    <td class="k" style="width: 11%; padding-right: 8pt;">تاريخ المناسبة</td>
    <td class="v num" style="width: 15%;">{{ $fill($data['booking_date'] ?? null) }}</td>
</tr></table>

<table class="boxes">
    <tr>
        <td style="width: 12%;">قسم رقم :</td>
        <td class="tick" style="width: 20%;">{{ $fill($data['sections'] ?? null) }}</td>
        <td style="width: 68%;"></td>
    </tr>
</table>

<table class="grid">
    <thead>
        <tr>
            <th style="width: 6%;">م</th>
            <th style="width: 33%;">الطلـب</th>
            <th style="width: 14%;">السعر الفردي</th>
            <th style="width: 9%;">العدد</th>
            <th style="width: 15%;">السعر الإجمالي</th>
            <th style="width: 23%;">ملاحظات</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $i => $line)
            <tr>
                <td class="c">{{ $i + 1 }}</td>
                <td>{{ $fill($line['name'] ?? null) }}</td>
                <td class="c num">{{ $fill($line['unit_price'] ?? null) }}</td>
                <td class="c num">{{ $qty($line['quantity'] ?? null) }}</td>
                <td class="c num"><b>{{ $fill($line['total_price'] ?? null) }}</b></td>
                <td>{{ $fill($line['notes'] ?? null) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="k" style="width: 11%;">الإجمالي</td>
        <td style="width: 14%;"><b class="num">{{ $fill($data['total_amount'] ?? null) }}</b></td>
        <td class="k" style="width: 11%;">المدفوع</td>
        <td style="width: 14%;"><b class="num">{{ $fill($data['deposit_amount'] ?? null) }}</b></td>
        <td class="k" style="width: 11%;">الباقي</td>
        <td style="width: 14%;"><b class="num">{{ $fill($data['remaining_amount'] ?? null) }}</b></td>
        <td class="k" style="width: 11%;">سند قبض</td>
        <td style="width: 14%;">{{ $fill(null) }}</td>
    </tr>
</table>

@if ($terms)
    <div class="notes">
        <span class="lbl">ملحوظة</span>
        <div class="notes-body">{{ $terms }}</div>
    </div>
@endif

<table class="sign">
    <tr>
        <td>
            <table class="ln"><tr>
                <td class="k" style="width: 30%;">اسم المؤجر :</td>
                <td class="v" style="width: 70%;">{{ $fill($issuer['manager_name'] ?? $issuer['business_name']) }}</td>
            </tr></table>
            <table class="ln"><tr>
                <td class="k" style="width: 30%;">التوقيع :</td>
                <td class="v" style="width: 70%;">
                    @if ($signaturePath)<img src="{{ $signaturePath }}" style="max-height: 26pt;" alt="">@endif
                    @if ($stampPath)<img src="{{ $stampPath }}" style="max-height: 26pt; margin-right: 8pt;" alt="">@endif
                    @if (! $signaturePath && ! $stampPath){{ $fill(null) }}@endif
                </td>
            </tr></table>
        </td>
        <td>
            <table class="ln"><tr>
                <td class="k" style="width: 32%;">اسم المستأجر :</td>
                <td class="v" style="width: 68%;">{{ $fill($contract->client?->name ?? ($data['client_name'] ?? null)) }}</td>
            </tr></table>
            <table class="ln"><tr>
                <td class="k" style="width: 32%;">التوقيع :</td>
                <td class="v" style="width: 68%;">{{ $fill(null) }}</td>
            </tr></table>
            <table class="ln"><tr>
                <td class="k" style="width: 32%;">الجوال :</td>
                <td class="v num" style="width: 68%;">{{ $fill($contract->client?->mobile ?? ($data['client_mobile'] ?? null)) }}</td>
            </tr></table>
        </td>
    </tr>
</table>

<div class="band">
    تنبيه / إذا لم يتم كتابة أي خدمة من الخدمات في العقد فهو خارج عن مسؤولية إدارة
    {{ $unitName ?? $issuer['business_name'] }} ويكون تحت مسؤولية المستأجر
</div>

@if ($issuer['address'])
    <div class="addr">{{ $issuer['address'] }}</div>
@endif
