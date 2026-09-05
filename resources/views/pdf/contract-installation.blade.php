{{--
    عقد التمديد والتركيب — the pools' printed pad, laid out as the paper form:
    bilingual field labels on dotted fill-in runs, the equipment grid in two
    halves, the two payments, the notes box and the validity band.

    Tables, not flex/grid: mpdf's layout engine collapses modern CSS into a
    single column, and every cell carries an explicit width for the same reason.
--}}
@php
    // A blank value stays empty — its dotted underline is the fill-in run.
    $fill = fn ($value) => filled($value) ? $value : "\u{00A0}";

    // Quantities are printed as written, not as 1.00 — the paper's grid holds
    // hand-written counts and «1» is what the salesman would put there.
    $qty = fn ($value) => filled($value) ? rtrim(rtrim(number_format((float) $value, 2), '0'), '.') : "\u{00A0}";

    // The equipment grid is two halves side by side, the lines split between
    // them, and padded to the pad's row count so a short contract still prints
    // a grid with room to write in.
    $half = (int) ceil(count($lines) / 2);
    $rightLines = array_slice($lines, 0, $half);
    $leftLines = array_slice($lines, $half);
    $gridRows = max(count($rightLines), count($leftLines), 9);
@endphp

<style>
    body { font-family: xbriyaz, sans-serif; color: #14224a; font-size: 10pt; line-height: 1.45; }
    table { width: 100%; border-collapse: collapse; }
    .num { direction: ltr; unicode-bidi: embed; }
    .rtl { direction: rtl; unicode-bidi: embed; }

    /* Letterhead: name and activity right, emblem centre, phones left. */
    .head td { vertical-align: top; }
    .head .biz { font-size: 16pt; font-weight: bold; color: #1b3a93; line-height: 1.2; }
    .head .sub { font-size: 9.5pt; font-weight: bold; color: #1b3a93; }
    .head .tel { font-size: 10pt; font-weight: bold; color: #1b3a93; }
    .rule { border-bottom: 1pt solid #1b3a93; margin: 5pt 0 6pt; }

    /* One table per LINE so each label is only as wide as its own text. */
    table.ln { margin: 0; }
    table.ln td { padding: 3pt 1pt 1pt; vertical-align: bottom; font-size: 10pt; }
    table.ln td.k { font-weight: bold; padding-left: 4pt; }
    table.ln td.v { border-bottom: 0.5pt dotted #6b6b6b; }
    /* The English half of a bilingual label, under its Arabic. */
    .en { display: block; font-size: 7pt; font-weight: normal; color: #6b7a99; }
    .serial { color: #c8102e; font-weight: bold; }

    /* The equipment grid: two halves side by side, each a quantity and its item. */
    table.eqgrid { margin-top: 6pt; border: 1pt solid #1b3a93; }
    table.eqgrid th { border: 0.5pt solid #1b3a93; background: #eef2fb; padding: 3pt;
                    font-size: 9.5pt; font-weight: bold; text-align: center; }
    table.eqgrid td { border: 0.5pt solid #1b3a93; padding: 3pt 4pt; font-size: 9.5pt; height: 13pt; }
    table.eqgrid td.q { text-align: center; }
    .code { font-size: 7.5pt; color: #6b7a99; }

    .split { margin: 5pt 0 2pt; text-align: center; font-size: 10pt; font-weight: bold; }

    .notes { margin-top: 4pt; }
    .notes .lbl { font-size: 10pt; font-weight: bold; color: #1b3a93; }
    .notes .body { font-size: 9pt; line-height: 1.6; white-space: pre-wrap; text-align: justify;
                   border: 0.5pt solid #c3cde6; padding: 5pt 6pt; margin-top: 2pt; }

    /* Signatures stay one block: a signature split from its name proves nothing. */
    .sign { margin-top: 8pt; }
    .sign td.col { width: 38%; vertical-align: top; padding: 0 10pt 0 0; }
    .sign td.stamp { width: 24%; text-align: center; vertical-align: middle; }
    .sign .who { font-size: 11pt; font-weight: bold; color: #1b3a93; margin-bottom: 3pt; }

    .addr { margin-top: 9pt; text-align: center; font-size: 9.5pt; font-weight: bold; color: #1b3a93; }
    .band { margin-top: 3pt; background: #1b3a93; color: #ffffff; text-align: center;
            padding: 4pt 8pt; font-size: 8.5pt; font-weight: bold; }
</style>

<table class="head">
    <tr>
        <td style="width: 40%;">
            <div class="biz">{{ $issuer['business_name'] }}</div>
            @if ($issuer['tax_number'])
                <div class="sub">الرقم الضريبي: <span class="num">{{ $issuer['tax_number'] }}</span></div>
            @endif
        </td>
        <td style="width: 22%; text-align: center;">
            @if ($logoPath)
                <img src="{{ $logoPath }}" style="max-height: 52pt; max-width: 100%;" alt="">
            @endif
        </td>
        <td style="width: 38%; text-align: left;">
            @if ($issuer['phone'])
                <div class="tel">ج: <span class="num">{{ $issuer['phone'] }}</span></div>
            @endif
            @if ($issuer['whatsapp'])
                <div class="tel">واتساب: <span class="num">{{ $issuer['whatsapp'] }}</span></div>
            @endif
        </td>
    </tr>
</table>

<div class="rule"></div>

<table class="ln"><tr>
    <td class="k" style="width: 11%;">رقم العقد<span class="en">Cont. No.</span></td>
    <td class="v serial num" style="width: 21%;">{{ $contract->number }}</td>
    <td class="k" style="width: 12%; padding-right: 10pt;">تاريخ العقد<span class="en">Cont. Date</span></td>
    <td class="v num" style="width: 24%;">{{ $fill($data['contract_date_hijri'] ?? null) }}</td>
    <td class="k" style="width: 8%;">هـ الموافق</td>
    <td class="v num" style="width: 24%;">{{ $fill($data['contract_date'] ?? $contract->created_at?->toDateString()) }}</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 10%;">طرف أول<span class="en">1st Part</span></td>
    <td class="v" style="width: 90%;"><span class="rtl">{{ $issuer['business_name'] }}</span></td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 19%;">طرف ثاني: السادة / المكرم<span class="en">M/S</span></td>
    <td class="v" style="width: 41%;">{{ $fill($contract->client?->name ?? ($data['client_name'] ?? null)) }}</td>
    <td class="k" style="width: 8%; padding-right: 10pt;">جوال<span class="en">Mob.</span></td>
    <td class="v num" style="width: 32%;">{{ $fill($contract->client?->mobile ?? ($data['client_mobile'] ?? null)) }}</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 11%;">عنوان الموقع<span class="en">Place</span></td>
    <td class="v" style="width: 61%;">{{ $fill($data['client_address'] ?? null) }}</td>
    <td class="k" style="width: 12%; padding-right: 10pt;">الهوية / السجل</td>
    <td class="v num" style="width: 16%;">{{ $fill($data['client_id_number'] ?? null) }}</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 14%;">قيمة العقد الكلية<span class="en">Total Amount</span></td>
    <td class="v" style="width: 30%;"><b class="num">{{ $data['total_amount'] ?? '' }}</b> ريال</td>
    <td class="k" style="width: 10%; padding-right: 10pt;">الدفعة الأولى</td>
    <td class="v" style="width: 20%;"><b class="num">{{ $fill($data['first_installment'] ?? null) }}</b></td>
    <td class="k" style="width: 10%; padding-right: 10pt;">الدفعة الثانية</td>
    <td class="v" style="width: 16%;"><b class="num">{{ $fill($data['second_installment'] ?? null) }}</b></td>
</tr></table>

@if (!empty($data['total_amount_words']))
    <table class="ln"><tr>
        <td class="k" style="width: 10%;">فقط وقدره</td>
        <td class="v" style="width: 90%;">{{ $data['total_amount_words'] }}</td>
    </tr></table>
@endif

{{-- Pool dimensions are measured on site, so the form prints them blank --}}
<table class="ln"><tr>
    <td class="k" style="width: 10%;">عرض المسبح<span class="en">Showing pool</span></td>
    <td class="v" style="width: 15%;">{{ $fill($data['pool_width'] ?? null) }}</td>
    <td class="k" style="width: 6%; padding-right: 8pt;">الطول<span class="en">Length</span></td>
    <td class="v" style="width: 15%;">{{ $fill($data['pool_length'] ?? null) }}</td>
    <td class="k" style="width: 8%; padding-right: 8pt;">أقل عمق<span class="en">Less depth</span></td>
    <td class="v" style="width: 15%;">{{ $fill($data['pool_min_depth'] ?? null) }}</td>
    <td class="k" style="width: 10%; padding-right: 8pt;">أقصى عمق<span class="en">Maximum depth</span></td>
    <td class="v" style="width: 15%;">{{ $fill($data['pool_max_depth'] ?? null) }}</td>
</tr></table>

<table class="eqgrid">
    <thead>
        <tr>
            <th style="width: 11%;">الكمية<span class="en">Qty.</span></th>
            <th style="width: 39%;">البيان<span class="en">Description</span></th>
            <th style="width: 11%;">الكمية<span class="en">Qty.</span></th>
            <th style="width: 39%;">البيان<span class="en">Description</span></th>
        </tr>
    </thead>
    <tbody>
        @for ($i = 0; $i < $gridRows; $i++)
            @php
                $right = $rightLines[$i] ?? null;
                $left = $leftLines[$i] ?? null;
            @endphp
            <tr>
                <td class="q num">{{ $qty($right['quantity'] ?? null) }}</td>
                <td>
                    {{ $fill($right['name'] ?? null) }}
                    @if (!empty($right['code']))<span class="code num">({{ $right['code'] }})</span>@endif
                </td>
                <td class="q num">{{ $qty($left['quantity'] ?? null) }}</td>
                <td>
                    {{ $fill($left['name'] ?? null) }}
                    @if (!empty($left['code']))<span class="code num">({{ $left['code'] }})</span>@endif
                </td>
            </tr>
        @endfor
    </tbody>
</table>

<div class="split">50 % عند التعاقد &nbsp;&nbsp;&nbsp; 50 % عند طلب توريد المعدات</div>

@if ($terms)
    <div class="notes">
        <span class="lbl">ملاحظات أو شروط أخرى<span class="en">Notes</span></span>
        <div class="body">{{ $terms }}</div>
    </div>
@endif

<table class="sign">
    <tr>
        <td class="col">
            <div class="who">طرف أول<span class="en">1st Part</span></div>
            <table class="ln"><tr>
                <td class="k" style="width: 22%;">الاسم:<span class="en">Name</span></td>
                <td class="v" style="width: 78%;">{{ $fill($issuer['manager_name'] ?? $issuer['business_name']) }}</td>
            </tr></table>
            <table class="ln"><tr>
                <td class="k" style="width: 22%;">التوقيع:<span class="en">Sign.</span></td>
                <td class="v" style="width: 78%;">
                    @if ($signaturePath)<img src="{{ $signaturePath }}" style="max-height: 26pt;" alt="">@else{{ $fill(null) }}@endif
                </td>
            </tr></table>
        </td>
        <td class="stamp">
            @if ($stampPath)
                <img src="{{ $stampPath }}" style="max-height: 46pt;" alt="">
            @else
                <div class="who">الختم<span class="en">STAMP</span></div>
            @endif
        </td>
        <td class="col">
            <div class="who">طرف ثاني<span class="en">2nd Part</span></div>
            <table class="ln"><tr>
                <td class="k" style="width: 22%;">الاسم:<span class="en">Name</span></td>
                <td class="v" style="width: 78%;">{{ $fill($contract->client?->name ?? ($data['client_name'] ?? null)) }}</td>
            </tr></table>
            <table class="ln"><tr>
                <td class="k" style="width: 22%;">التوقيع:<span class="en">Sign.</span></td>
                <td class="v" style="width: 78%;">{{ $fill(null) }}</td>
            </tr></table>
        </td>
    </tr>
</table>

@if ($issuer['address'])
    <div class="addr">{{ $issuer['address'] }}</div>
@endif

<div class="band">
    يعتبر العقد ساري المفعول من تاريخ توقيع العقد ولمدة سنة كاملة،
    وإذا حصل تغيير بعد عام من تاريخ توقيع العقد بالأسعار يلتزم الطرف الثاني بدفع الفرق
</div>
