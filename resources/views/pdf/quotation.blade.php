<style>
    body { font-family: xbriyaz, sans-serif; color: #0f172a; font-size: 10pt; line-height: 1.6; }
    .head { border-bottom: 1.2pt solid #0f172a; padding-bottom: 6pt; margin-bottom: 10pt; }
    .head h1 { font-size: 14pt; font-weight: bold; margin: 0 0 3pt; color: #1e3a8a; }
    table { width: 100%; border-collapse: collapse; }
    .parties td { vertical-align: top; width: 40%; }
    .parties td.qr { width: 20%; text-align: center; }
    .box { border: 0.6pt solid #cbd5e1; border-radius: 4pt; padding: 6pt; min-height: 70pt; }
    .box h3 { margin: 0 0 4pt; font-size: 10pt; font-weight: bold; text-align: center;
              border: 0.6pt solid #cbd5e1; border-radius: 3pt; padding: 3pt; background: #f8fafc; }
    .box p { margin: 0 0 2pt; font-size: 9pt; }
    h2.section { font-size: 11pt; font-weight: bold; margin: 15pt 0 5pt; color: #1e3a8a; }
    .items { width: 100%; border: 0.6pt solid #cbd5e1; margin-bottom: 15pt; }
    .items th { background: #f1f5f9; border: 0.6pt solid #cbd5e1; padding: 5pt; text-align: center; font-weight: bold; font-size: 9pt; }
    .items td { border: 0.6pt solid #cbd5e1; padding: 5pt; font-size: 9pt; }
    .items td.num { text-align: center; }
    .totals { width: 40%; float: left; border: 0.6pt solid #cbd5e1; border-radius: 4pt; }
    .totals table { width: 100%; }
    .totals td { padding: 4pt 6pt; border-bottom: 0.6pt solid #f1f5f9; font-size: 9pt; }
    .totals tr:last-child td { border-bottom: none; }
    .totals .label { font-weight: bold; color: #475569; }
    .totals .val { text-align: left; font-weight: bold; }
    .totals .grand td { background: #1e3a8a; color: white; font-size: 10pt; }
    .notes { margin-top: 20pt; padding: 8pt; background: #f8fafc; border-radius: 4pt; border: 0.6pt solid #e2e8f0; font-size: 9pt; white-space: pre-wrap; }
    .ltr { direction: ltr; unicode-bidi: embed; display: inline-block; }
    .clearfix::after { content: ""; clear: both; display: table; }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-left { text-align: left; }
</style>

<div class="head">
    <table>
        <tr>
            <td style="text-align: right; width: 70%;">
                <h1>عرض سعر (Quotation)</h1>
                <div>رقم العرض: <span class="ltr">{{ $quotation->number }}</span></div>
                <div>تاريخ الإصدار: <span class="ltr">{{ $quotation->created_at->format('Y-m-d') }}</span></div>
                @if ($quotation->valid_until)
                    <div>صالح حتى: <span class="ltr">{{ $quotation->valid_until->format('Y-m-d') }}</span></div>
                @endif
            </td>
            <td style="text-align: left; width: 30%;">
                @if ($logoPath)
                    <img src="{{ $logoPath }}" style="max-height: 60pt; max-width: 100%;" alt="">
                @endif
            </td>
        </tr>
    </table>
</div>

<table class="parties">
    <tr>
        <td>
            <div class="box">
                <h3>بيانات العميل</h3>
                <p><b>الاسم:</b> {{ $quotation->client?->name ?? '—' }}</p>
                @if ($quotation->client?->mobile)
                    <p><b>الجوال:</b> <span class="ltr">{{ $quotation->client->mobile }}</span></p>
                @endif
            </div>
        </td>
        <td class="qr">
            @if ($qrDataUrl)
                <img src="{{ $qrDataUrl }}" style="width: 80pt; height: 80pt;" alt="ZATCA QR">
            @endif
        </td>
        <td>
            <div class="box">
                <h3>بيانات المُصدر</h3>
                <p><b>{{ $settings->business_name }}</b></p>
                @if ($settings->address)<p>{{ $settings->address }}</p>@endif
                @if ($settings->phone)<p><b>هاتف:</b> <span class="ltr">{{ $settings->phone }}</span></p>@endif
                @if ($settings->tax_enabled && $settings->tax_number)<p><b>الرقم الضريبي:</b> <span class="ltr">{{ $settings->tax_number }}</span></p>@endif
            </div>
        </td>
    </tr>
</table>

<h2 class="section">تفاصيل العرض</h2>
<table class="items">
    <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th style="width: 40%; text-align: right;">الصنف / البيان</th>
            <th style="width: 10%;">الكمية</th>
            <th style="width: 15%;">سعر الوحدة</th>
            <th style="width: 15%;">الضريبة</th>
            <th style="width: 15%;">الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($quotation->items as $index => $line)
            <tr>
                <td class="num ltr">{{ $index + 1 }}</td>
                <td>
                    <b>{{ $line->item?->name }}</b>
                    @if ($line->item?->code)
                        <br><span style="font-size: 8pt; color: #64748b;" class="ltr">{{ $line->item->code }}</span>
                    @endif
                </td>
                <td class="num ltr">{{ number_format($line->quantity, 3) }}</td>
                <td class="num ltr">{{ number_format($line->unit_price, 2) }}</td>
                <td class="num ltr">{{ number_format($line->quantity * $line->unit_price * (($line->item?->tax_rate ?? 15) / 100), 2) }}</td>
                <td class="num ltr" style="font-weight: bold;">
                    {{ number_format(($line->quantity * $line->unit_price) + ($line->quantity * $line->unit_price * (($line->item?->tax_rate ?? 15) / 100)), 2) }}
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="clearfix">
    <div class="totals ltr" style="float: left; width: 50%; direction: rtl;">
        <table>
            <tr>
                <td class="label">الإجمالي قبل الضريبة</td>
                <td class="val ltr">{{ number_format($quotation->subtotal, 2) }} ريال</td>
            </tr>
            @if ($quotation->discount_amount > 0)
                <tr>
                    <td class="label" style="color: #b91c1c;">الخصم الممنوح</td>
                    <td class="val ltr" style="color: #b91c1c;">{{ number_format($quotation->discount_amount, 2) }} ريال</td>
                </tr>
            @endif
            <tr>
                <td class="label">ضريبة القيمة المضافة</td>
                <td class="val ltr">{{ number_format($quotation->tax_amount, 2) }} ريال</td>
            </tr>
            <tr class="grand">
                <td class="label" style="color: white;">الإجمالي المستحق</td>
                <td class="val ltr">{{ number_format($quotation->total_amount, 2) }} ريال</td>
            </tr>
        </table>
    </div>
</div>

@if ($quotation->notes)
    <div class="notes">
        <b>الشروط والملاحظات:</b>
        <br>
        {{ $quotation->notes }}
    </div>
@endif

<div style="margin-top: 30pt; text-align: center; font-size: 9pt; color: #64748b;">
    <p>هذا العرض آلي ولا يعتبر ملزماً ما لم يتم توقيع عقد أو تعميد رسمي من قبل الطرفين.</p>
</div>
