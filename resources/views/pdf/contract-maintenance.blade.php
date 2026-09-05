{{--
    عرض سعر صيانة مسابح شهريًا — the pools' monthly-maintenance sheet, laid out
    as the paper: centred letterhead, the addressee, the priced table, the
    totals block on its left and the visit notes under them.

    Tables, not flex/grid: mpdf's layout engine collapses modern CSS into a
    single column, and every cell carries an explicit width for the same reason.
--}}
@php
    $fill = fn ($value) => filled($value) ? $value : "\u{00A0}";

    // Quantities are printed as written — «2», not 2.00.
    $qty = fn ($value) => filled($value) ? rtrim(rtrim(number_format((float) $value, 2), '0'), '.') : "\u{00A0}";

    // A money row is printed only when it carries an amount: the paper has no
    // «الخصم 0.00» line, and a sheet with no VAT shows no VAT row.
    $carries = fn ($value) => filled($value) && (float) str_replace(',', '', (string) $value) != 0.0;

    // The pad is printed with room to write in, so a short sheet still rules
    // enough lines for the visits to be listed by hand.
    $rows = max(count($lines), 4);
@endphp

<style>
    body { font-family: xbriyaz, sans-serif; color: #14224a; font-size: 10pt; line-height: 1.6; }
    table { width: 100%; border-collapse: collapse; }
    .num { direction: ltr; unicode-bidi: embed; }

    .head { text-align: center; margin-bottom: 10pt; }
    .head .biz { font-size: 13pt; font-weight: bold; margin-top: 8pt; }
    .head .line { font-size: 11.5pt; font-weight: bold; margin-top: 4pt; }

    .to { font-size: 12pt; font-weight: bold; margin: 14pt 0 8pt; }
    .subject { text-align: center; font-size: 12pt; font-weight: bold; margin-bottom: 14pt; }

    /* The priced table — thin black rules, as the paper is ruled. */
    table.items { border: 0.7pt solid #000000; }
    table.items th, table.items td { border: 0.5pt solid #000000; padding: 4pt 5pt;
                                     font-size: 10pt; text-align: center; height: 14pt; }
    table.items th { font-weight: bold; }
    table.items td.desc { text-align: right; }
    .code { font-size: 7.5pt; color: #6b7a99; }

    /* The totals sit alone at the left, under the table — floated, because
       a plain table in an RTL page would hug the right margin instead. */
    .totals { width: 45%; float: left; margin-top: 10pt; }
    .totals table { border: 0.7pt solid #000000; }
    .totals td { border: 0.5pt solid #000000; padding: 4pt 6pt; font-size: 10pt; height: 14pt; }
    .totals td.lbl { text-align: center; font-weight: bold; width: 55%; }
    .totals td.val { text-align: center; width: 45%; }
    .clearfix::after { content: ""; clear: both; display: table; }

    .notes { margin-top: 26pt; }
    .notes .lbl { font-size: 12pt; font-weight: bold; }
    .notes ul { margin: 8pt 22pt 0 0; padding: 0; }
    .notes li { font-size: 11pt; margin-bottom: 6pt; }
    .notes .plain { font-size: 11pt; white-space: pre-wrap; margin-top: 8pt; }
</style>

<div class="head">
    @if ($logoPath)
        <img src="{{ $logoPath }}" style="max-height: 80pt; max-width: 65%;" alt="">
    @endif
    <div class="biz">{{ $issuer['business_name'] }}</div>
    @if ($issuer['phone'] || $issuer['whatsapp'])
        <div class="line">
            رقم الجوال:
            <span class="num">{{ $issuer['phone'] }}@if ($issuer['whatsapp']) - {{ $issuer['whatsapp'] }}@endif</span>
        </div>
    @endif
    @if ($issuer['commercial_register'])
        <div class="line">س ت: <span class="num">{{ $issuer['commercial_register'] }}</span></div>
    @endif
</div>

<div class="to">
    إلى السـادة: {{ $fill($contract->client?->name ?? ($data['client_name'] ?? null)) }}
</div>

<div class="subject">{{ \App\Support\PoolMaintenanceContractTemplate::HEADING }}</div>

<table class="items">
    <thead>
        <tr>
            <th style="width: 7%;">م</th>
            <th style="width: 48%;">البيان</th>
            <th style="width: 13%;">العدد</th>
            <th style="width: 16%;">السعر</th>
            <th style="width: 16%;">الاجمالي</th>
        </tr>
    </thead>
    <tbody>
        @for ($i = 0; $i < $rows; $i++)
            @php $line = $lines[$i] ?? null; @endphp
            <tr>
                <td class="num">{{ $line ? $i + 1 : "\u{00A0}" }}</td>
                <td class="desc">
                    {{ $fill($line['name'] ?? null) }}
                    @if (!empty($line['code']))<span class="code num">({{ $line['code'] }})</span>@endif
                </td>
                <td class="num">{{ $qty($line['quantity'] ?? null) }}</td>
                <td class="num">{{ $fill($line['unit_price'] ?? null) }}</td>
                <td class="num">{{ $fill($line['total_price'] ?? null) }}</td>
            </tr>
        @endfor
    </tbody>
</table>

<div class="clearfix">
    <div class="totals">
        <table>
            <tr>
                <td class="lbl">الاجمــــالي</td>
                <td class="val num">{{ $fill($data['subtotal'] ?? $data['total_amount'] ?? null) }}</td>
            </tr>
            @if ($carries($data['discount_amount'] ?? null))
                <tr>
                    <td class="lbl">الخصــــم</td>
                    <td class="val num">{{ $data['discount_amount'] }}</td>
                </tr>
            @endif
            @if ($carries($data['tax_amount'] ?? null))
                <tr>
                    <td class="lbl">ضريبة القيمة المضافة</td>
                    <td class="val num">{{ $data['tax_amount'] }}</td>
                </tr>
            @endif
            <tr>
                <td class="lbl">الاجمالي المستحق</td>
                <td class="val num">{{ $fill($data['total_amount'] ?? null) }}</td>
            </tr>
        </table>
    </div>
</div>

@if ($terms)
    <div class="notes">
        <span class="lbl">ملاحظـــات :</span>
        {{--
            The notes are written as one bullet per line, so they are printed as
            a list; wording that is not bulleted is printed as it stands rather
            than forced into bullets it was not written as.
        --}}
        @php
            $bullets = collect(preg_split('/\R/', $terms))
                ->map(fn ($line) => ltrim(trim($line), "•-–* \t"))
                ->filter()
                ->all();
            $bulleted = str_contains($terms, '•');
        @endphp

        @if ($bulleted)
            <ul>
                @foreach ($bullets as $bullet)
                    <li>{{ $bullet }}</li>
                @endforeach
            </ul>
        @else
            <div class="plain">{{ $terms }}</div>
        @endif
    </div>
@endif
