{{--
    ورقة عرض السعر — mpdf لا يُحسن الحدود على div داخل خلايا الجداول ولا
    inline-block، فكانت تُرسم خطوطٌ تحت كل سطر. الورقة كلها جداول بحدود على
    الخلايا نفسها، وهو ما يرسمه mpdf كما يُقصد.
--}}
@php
    $showsTax = (float) $quotation->tax_amount > 0;
    $lineTax = fn ($line) => (float) $line->tax_amount;
    $money = fn ($n) => number_format((float) $n, 2);
    $navy = '#1e3a8a';
@endphp
<style>
    body { font-family: cairo, sans-serif; color: #0f172a; font-size: 9.5pt; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }

    .brand-name { font-size: 13pt; font-weight: bold; color: {{ $navy }}; }
    .muted { color: #64748b; font-size: 8.5pt; }

    .doc-title td { background: {{ $navy }}; color: #ffffff; text-align: center; padding: 5pt 8pt 1pt; font-size: 15pt; font-weight: bold; }
    .doc-title td.sub { padding: 0 8pt 5pt; font-size: 7.5pt; font-weight: normal; color: #c7d2fe; letter-spacing: 2pt; }
    .meta td { padding: 3pt 8pt; border-bottom: 0.5pt solid #e2e8f0; font-size: 9pt; }
    .meta .k { color: #64748b; }
    .meta .v { text-align: left; font-weight: bold; }

    .rule { border-bottom: 1.5pt solid {{ $navy }}; }

    .card-title { background: #f1f5f9; color: {{ $navy }}; font-weight: bold; padding: 4pt 8pt; border: 0.6pt solid #cbd5e1; }
    .card-body { padding: 6pt 8pt; border: 0.6pt solid #cbd5e1; border-top: none; }

    .items th { background: {{ $navy }}; color: #ffffff; padding: 6pt 5pt; font-size: 9pt; font-weight: bold; text-align: center; border: 0.6pt solid {{ $navy }}; }
    .items td { padding: 6pt 5pt; border: 0.6pt solid #cbd5e1; font-size: 9pt; }
    .items tr.alt td { background: #f8fafc; }
    .c { text-align: center; }

    .totals td { padding: 5pt 8pt; font-size: 9.5pt; border: 0.6pt solid #cbd5e1; }
    .totals .k { color: #475569; font-weight: bold; }
    .totals .v { text-align: left; font-weight: bold; }
    .totals .grand td { background: {{ $navy }}; color: #ffffff; font-size: 11pt; border-color: {{ $navy }}; }

    .notes { padding: 7pt 9pt; background: #f8fafc; border: 0.6pt solid #e2e8f0; font-size: 9pt; }
    .sign td { text-align: center; font-size: 9pt; color: #475569; }
    .sign td.line { border-top: 0.6pt solid #94a3b8; padding-top: 4pt; }
</style>

{{-- ── الرأس: الجهة المُصدِرة يمينًا، ومربع المستند يسارًا ── --}}
<table>
    <tr>
        <td style="width: 58%;">
            <table>
                <tr>
                    @if ($logoPath)
                        <td style="width: 32%; vertical-align: middle;">
                            <img src="{{ $logoPath }}" style="max-height: 62pt; max-width: 100%;" alt="">
                        </td>
                    @endif
                    <td style="vertical-align: middle; padding-right: 6pt;">
                        <div class="brand-name">{{ $issuer['business_name'] }}</div>
                        @if ($issuer['address'])<div class="muted">{{ $issuer['address'] }}</div>@endif
                        @if ($issuer['phone'])<div class="muted">هاتف: {{ $issuer['phone'] }}</div>@endif
                        @if ($issuer['email'])<div class="muted">{{ $issuer['email'] }}</div>@endif
                        @if ($issuer['tax_number'])<div class="muted">الرقم الضريبي: {{ $issuer['tax_number'] }}</div>@endif
                        @if ($issuer['commercial_register'])<div class="muted">السجل التجاري: {{ $issuer['commercial_register'] }}</div>@endif
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 4%;"></td>
        <td style="width: 38%;">
            <table class="doc-title">
                <tr><td>عرض سعر</td></tr>
                <tr><td class="sub">QUOTATION</td></tr>
            </table>
            <table class="meta">
                <tr><td class="k">رقم العرض</td><td class="v">{{ $quotation->number }}</td></tr>
                <tr><td class="k">تاريخ الإصدار</td><td class="v">{{ $quotation->created_at->format('Y-m-d') }}</td></tr>
                @if ($quotation->valid_until)
                    <tr><td class="k">صالح حتى</td><td class="v">{{ $quotation->valid_until->format('Y-m-d') }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<table style="margin: 10pt 0;"><tr><td class="rule"></td></tr></table>

{{-- ── العميل ── --}}
<table>
    <tr><td class="card-title">مقدَّم إلى</td></tr>
    <tr>
        <td class="card-body">
            <table>
                <tr>
                    <td style="width: 55%;"><span class="muted">العميل:</span> <b>{{ $quotation->client?->name ?? '—' }}</b></td>
                    <td style="width: 45%;">
                        @if ($quotation->client?->mobile)
                            <span class="muted">الجوال:</span> <b>{{ $quotation->client->mobile }}</b>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ── الأصناف ──
    ضريبة السطر تُقرأ كما حُفظت عليه، فالبند يُعرض بضريبة أو معفى منها
    وحده، وتجمع السطور إجمالي الورقة. وعرضٌ بلا ضريبة تُطوى أعمدتها. --}}
<table class="items" style="margin-top: 12pt;">
    <thead>
        <tr>
            <th style="width: 6%;">#</th>
            <th style="width: {{ $showsTax ? '39%' : '54%' }}; text-align: right;">الصنف / البيان</th>
            <th style="width: 12%;">الكمية</th>
            <th style="width: 14%;">سعر الوحدة</th>
            @if ($showsTax)<th style="width: 14%;">الضريبة</th>@endif
            <th style="width: 15%;">الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($quotation->items as $index => $line)
            <tr @class(['alt' => $index % 2 === 1])>
                <td class="c">{{ $index + 1 }}</td>
                <td>
                    <b>{{ $line->item?->name }}</b>
                    @if ($line->item?->code)
                        <div class="muted">{{ $line->item->code }}</div>
                    @endif
                </td>
                <td class="c">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }}</td>
                <td class="c">{{ $money($line->unit_price) }}</td>
                @if ($showsTax)
                    <td class="c">
                        @if ($line->is_taxable){{ $money($lineTax($line)) }}@else<span class="muted">معفى</span>@endif
                    </td>
                @endif
                <td class="c"><b>{{ $money((float) $line->total_price + $lineTax($line)) }}</b></td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- ── الإجماليات: في الجهة اليسرى من الورقة ── --}}
<table style="margin-top: 10pt;">
    <tr>
        <td style="width: 55%;"></td>
        <td style="width: 45%;">
            <table class="totals">
                <tr>
                    <td class="k">{{ $showsTax ? 'الإجمالي قبل الضريبة' : 'الإجمالي' }}</td>
                    <td class="v">{{ $money($quotation->subtotal) }} ريال</td>
                </tr>
                @if ($quotation->discount_amount > 0)
                    <tr>
                        <td class="k" style="color: #b91c1c;">الخصم</td>
                        <td class="v" style="color: #b91c1c;">{{ $money($quotation->discount_amount) }} ريال</td>
                    </tr>
                @endif
                @if ($showsTax)
                    <tr>
                        <td class="k">ضريبة القيمة المضافة</td>
                        <td class="v">{{ $money($quotation->tax_amount) }} ريال</td>
                    </tr>
                @endif
                <tr class="grand">
                    <td style="font-weight: bold;">الإجمالي المستحق</td>
                    <td style="text-align: left; font-weight: bold;">{{ $money($quotation->total_amount) }} ريال</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if ($quotation->notes)
    <table style="margin-top: 14pt;">
        <tr>
            <td class="notes">
                <b style="color: {{ $navy }};">الشروط والملاحظات</b><br>
                {!! nl2br(e($quotation->notes)) !!}
            </td>
        </tr>
    </table>
@endif

{{-- ── الاعتماد: الختم والتوقيع إن ضُبطا للجهة ── --}}
<table class="sign" style="margin-top: 26pt;">
    <tr>
        <td style="width: 38%; height: 60pt; vertical-align: bottom;">
            @if ($signaturePath)<img src="{{ $signaturePath }}" style="max-height: 55pt; max-width: 100%;" alt="">@endif
        </td>
        <td style="width: 24%;"></td>
        <td style="width: 38%; height: 60pt; vertical-align: bottom;">
            @if ($stampPath)<img src="{{ $stampPath }}" style="max-height: 58pt; max-width: 100%;" alt="">@endif
        </td>
    </tr>
    <tr>
        <td class="line">{{ $issuer['manager_name'] ?: 'المدير المسؤول' }}</td>
        <td></td>
        <td class="line">الختم</td>
    </tr>
</table>

<p style="margin-top: 18pt; text-align: center; font-size: 8pt; color: #94a3b8;">
    هذا العرض لا يُعدّ ملزمًا ما لم يُوقَّع عقد أو تعميد رسمي من الطرفين.
</p>
