{{--
    سند قبضٍ على عقد — بتصميم عرض السعر والعقد اللذين تصدرهما الجهة نفسها.

    كله جداول بحدود على الخلايا نفسها: mpdf يرسم خطًّا تحت كل سطر من div
    داخل الخلايا، ولا يعرف flex/grid.
--}}
@php
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

    .amount td { background: {{ $navy }}; color: #ffffff; padding: 8pt 10pt; font-size: 12pt; font-weight: bold; }
    .amount td.v { text-align: left; font-size: 16pt; }

    .rows td { padding: 7pt 9pt; border: 0.6pt solid #cbd5e1; font-size: 10pt; }
    .rows td.k { background: #f1f5f9; color: {{ $navy }}; font-weight: bold; width: 26%; }

    .totals td { padding: 5pt 8pt; font-size: 9.5pt; border: 0.6pt solid #cbd5e1; }
    .totals .k { color: #475569; font-weight: bold; }
    .totals .v { text-align: left; font-weight: bold; }

    .sign td { text-align: center; font-size: 9pt; color: #475569; }
    .sign td.line { border-top: 0.6pt solid #94a3b8; padding-top: 4pt; }
</style>

{{-- ── الرأس: الجهة المُصدِرة يمينًا، ومربع السند يسارًا ── --}}
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
                        @if ($issuer['email'] ?? null)<div class="muted">{{ $issuer['email'] }}</div>@endif
                        @if ($issuer['tax_number'])<div class="muted">الرقم الضريبي: {{ $issuer['tax_number'] }}</div>@endif
                        @if ($issuer['commercial_register'])<div class="muted">السجل التجاري: {{ $issuer['commercial_register'] }}</div>@endif
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 4%;"></td>
        <td style="width: 38%;">
            <table class="doc-title">
                <tr><td>سند قبض</td></tr>
                <tr><td class="sub">RECEIPT VOUCHER</td></tr>
            </table>
            <table class="meta">
                <tr><td class="k">رقم السند</td><td class="v">{{ $voucher->number }}</td></tr>
                <tr><td class="k">التاريخ</td><td class="v">{{ $voucher->voucher_date->format('Y-m-d') }}</td></tr>
                @if ($dateHijri)
                    <tr><td class="k">الموافق</td><td class="v">{{ $dateHijri }}</td></tr>
                @endif
                <tr><td class="k">رقم العقد</td><td class="v">{{ $contract->number }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table style="margin: 10pt 0;"><tr><td class="rule"></td></tr></table>

{{-- ── المبلغ ── --}}
<table class="amount">
    <tr>
        <td>المبلغ المقبوض</td>
        <td class="v">{{ $money($amount) }} ريال</td>
    </tr>
</table>

<table class="rows" style="margin-top: 10pt;">
    <tr>
        <td class="k">استلمنا من</td>
        <td><b>{{ $contract->client?->name ?? ($contract->data['client_name'] ?? '—') }}</b>
            @if ($contract->client?->mobile)<span class="muted"> — {{ $contract->client->mobile }}</span>@endif
        </td>
    </tr>
    <tr>
        <td class="k">مبلغًا وقدره</td>
        <td>{{ $amountWords }}</td>
    </tr>
    <tr>
        <td class="k">وذلك عن</td>
        <td>{{ $voucher->description ?: 'دفعة على العقد '.$contract->number }}</td>
    </tr>
    <tr>
        <td class="k">طريقة الدفع</td>
        <td>
            {{ $voucher->methodLabel() }}
            @if ($voucher->reference && $voucher->reference !== $contract->number)
                <span class="muted"> — مرجع: {{ $voucher->reference }}</span>
            @endif
        </td>
    </tr>
</table>

{{-- ── حال العقد يوم كُتب السند ── --}}
@if ($total !== null)
    <table style="margin-top: 10pt;">
        <tr>
            <td style="width: 55%;"></td>
            <td style="width: 45%;">
                <table class="totals">
                    <tr><td class="k">قيمة العقد</td><td class="v">{{ $money($total) }} ريال</td></tr>
                    <tr><td class="k" style="color: #047857;">المدفوع حتى تاريخه</td><td class="v" style="color: #047857;">{{ $money($paidToDate) }} ريال</td></tr>
                    <tr><td class="k" style="color: #b91c1c;">المتبقي</td><td class="v" style="color: #b91c1c;">{{ $money($remaining) }} ريال</td></tr>
                </table>
            </td>
        </tr>
    </table>
@endif

{{-- ── الاعتماد ── --}}
<table class="sign" style="margin-top: 30pt;">
    <tr>
        <td style="width: 30%; height: 60pt; vertical-align: bottom;">
            @if ($signaturePath)<img src="{{ $signaturePath }}" style="max-height: 55pt; max-width: 100%;" alt="">@endif
        </td>
        <td style="width: 5%;"></td>
        <td style="width: 30%; height: 60pt; vertical-align: bottom;">
            @if ($stampPath)<img src="{{ $stampPath }}" style="max-height: 58pt; max-width: 100%;" alt="">@endif
        </td>
        <td style="width: 5%;"></td>
        <td style="width: 30%; height: 60pt;"></td>
    </tr>
    <tr>
        <td class="line">المستلم: {{ $issuer['manager_name'] ?: 'المحاسب' }}</td>
        <td></td>
        <td class="line">الختم</td>
        <td></td>
        <td class="line">المسلِّم: {{ $contract->client?->name ?? '—' }}</td>
    </tr>
</table>
