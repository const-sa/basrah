{{--
    عرض سعر صيانة مسابح شهريًا — ورقة الصيانة الشهرية، مرسومةً كعرض السعر
    الذي تُولَّد منه: الجهة وشعارها يمينًا ومربع المستند برقمه يسارًا، وبطاقة
    العميل، وجدول البنود، والإجماليات، وملاحظات الزيارات، ثم الاعتماد.

    كله جداول بحدود على الخلايا نفسها: mpdf يرسم خطًّا تحت كل سطر من div
    داخل الخلايا، ولا يعرف flex/grid.
--}}
@php
    $fill = fn ($value) => filled($value) ? $value : "\u{00A0}";

    // Quantities are printed as written — «2», not 2.00.
    // A count is printed as a count; a cell holding words instead — «حسب
    // الاتفاق» — is printed as it was written.
    $qty = fn ($value) => blank($value)
        ? "\u{00A0}"
        : (is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2), '0'), '.') : $value);

    // A money row is printed only when it carries an amount: the paper has no
    // «الخصم 0.00» line, and a sheet with no VAT shows no VAT row.
    $carries = fn ($value) => filled($value) && (float) str_replace(',', '', (string) $value) != 0.0;

    $riyal = fn ($value) => filled($value) ? $value.' ريال' : '—';

    $clientName = $contract->client?->name ?? ($data['client_name'] ?? null);
    $clientMobile = $contract->client?->mobile ?? ($data['client_mobile'] ?? null);

    $navy = '#1e3a8a';
@endphp
<style>
    body { font-family: cairo, sans-serif; color: #0f172a; font-size: 9.5pt; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }

    .brand-name { font-size: 13pt; font-weight: bold; color: {{ $navy }}; }
    .muted { color: #64748b; font-size: 8.5pt; }

    .doc-title td { background: {{ $navy }}; color: #ffffff; text-align: center; padding: 5pt 8pt 1pt; font-size: 13pt; font-weight: bold; }
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
                        @if ($issuer['phone'] || $issuer['whatsapp'])
                            <div class="muted">هاتف: {{ $issuer['phone'] }}@if ($issuer['whatsapp'] && $issuer['whatsapp'] !== $issuer['phone']) - {{ $issuer['whatsapp'] }}@endif</div>
                        @endif
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
                <tr><td>{{ \App\Support\PoolMaintenanceContractTemplate::HEADING }}</td></tr>
                <tr><td class="sub">MONTHLY MAINTENANCE</td></tr>
            </table>
            <table class="meta">
                <tr><td class="k">رقم العقد</td><td class="v">{{ $contract->number }}</td></tr>
                <tr>
                    <td class="k">تاريخ العقد</td>
                    <td class="v">{{ $data['contract_date'] ?? $contract->created_at?->format('Y-m-d') }}</td>
                </tr>
                @if (!empty($data['quotation_number']))
                    <tr><td class="k">عرض السعر</td><td class="v">{{ $data['quotation_number'] }}</td></tr>
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
                    <td style="width: 55%;"><span class="muted">العميل:</span> <b>{{ $clientName ?? '—' }}</b></td>
                    <td style="width: 45%;">
                        @if ($clientMobile)
                            <span class="muted">الجوال:</span> <b>{{ $clientMobile }}</b>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ── البنود ── --}}
<table class="items" style="margin-top: 12pt;">
    <thead>
        <tr>
            <th style="width: 6%;">#</th>
            <th style="width: 50%; text-align: right;">البيان</th>
            <th style="width: 12%;">العدد</th>
            <th style="width: 16%;">السعر</th>
            <th style="width: 16%;">الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($lines as $index => $line)
            <tr @class(['alt' => $index % 2 === 1])>
                <td class="c">{{ $index + 1 }}</td>
                <td>
                    <b>{{ $fill($line['name'] ?? null) }}</b>
                    @if (!empty($line['code']))
                        <div class="muted">{{ $line['code'] }}</div>
                    @endif
                </td>
                <td class="c">{{ $qty($line['quantity'] ?? null) }}</td>
                <td class="c">{{ $fill($line['unit_price'] ?? null) }}</td>
                <td class="c"><b>{{ $fill($line['total_price'] ?? null) }}</b></td>
            </tr>
        @empty
            <tr><td colspan="5" class="c muted">لا بنود</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ── الإجماليات: في الجهة اليسرى من الورقة ── --}}
<table style="margin-top: 10pt;">
    <tr>
        <td style="width: 55%;"></td>
        <td style="width: 45%;">
            <table class="totals">
                <tr>
                    <td class="k">الإجمالي</td>
                    <td class="v">{{ $riyal($data['subtotal'] ?? $data['total_amount'] ?? null) }}</td>
                </tr>
                @if ($carries($data['discount_amount'] ?? null))
                    <tr>
                        <td class="k" style="color: #b91c1c;">الخصم</td>
                        <td class="v" style="color: #b91c1c;">{{ $riyal($data['discount_amount']) }}</td>
                    </tr>
                @endif
                @if ($carries($data['tax_amount'] ?? null))
                    <tr>
                        <td class="k">ضريبة القيمة المضافة</td>
                        <td class="v">{{ $riyal($data['tax_amount']) }}</td>
                    </tr>
                @endif
                <tr class="grand">
                    <td style="font-weight: bold;">الإجمالي المستحق</td>
                    <td style="text-align: left; font-weight: bold;">{{ $riyal($data['total_amount'] ?? null) }}</td>
                </tr>
                {{-- المدفوع والمتبقي: ما رحّلته سندات القبض على العقد --}}
                @if ($carries($data['deposit_amount'] ?? null))
                    <tr>
                        <td class="k" style="color: #047857;">المدفوع</td>
                        <td class="v" style="color: #047857;">{{ $riyal($data['deposit_amount']) }}</td>
                    </tr>
                    @if (!empty($data['remaining_amount']))
                        <tr>
                            <td class="k">المتبقي</td>
                            <td class="v">{{ $riyal($data['remaining_amount']) }}</td>
                        </tr>
                    @endif
                @endif
            </table>
        </td>
    </tr>
</table>

@if ($terms)
    {{--
        The notes are written as one bullet per line, so they are printed as
        a list; wording that is not bulleted is printed as it stands rather
        than forced into bullets it was not written as.
    --}}
    @php
        // «u» لازم: بدونه يعدّ \R البايت 0x85 سطرًا جديدًا، وهو نصف
        // حرف «م» في UTF-8، فتنقطع الملاحظة عند كل ميم. والتقليم
        // بتعبير لا بـltrim لأن «•» و«–» حروفٌ متعددة البايتات.
        $bullets = collect(preg_split('/\R/u', $terms))
            ->map(fn ($line) => preg_replace('/^[\s•\-–*]+/u', '', trim($line)))
            ->filter()
            ->all();
        $bulleted = str_contains($terms, '•');
    @endphp
    <table style="margin-top: 14pt;">
        <tr>
            <td class="notes">
                <b style="color: {{ $navy }};">الشروط والملاحظات</b><br>
                @if ($bulleted)
                    @foreach ($bullets as $bullet)
                        • {{ $bullet }}<br>
                    @endforeach
                @else
                    {!! nl2br(e($terms)) !!}
                @endif
            </td>
        </tr>
    </table>
@endif

{{-- ── الاعتماد: الختم والتوقيع إن ضُبطا للجهة، وتوقيع العميل ── --}}
<table class="sign" style="margin-top: 26pt;">
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
        <td class="line">{{ $issuer['manager_name'] ?: 'المدير المسؤول' }}</td>
        <td></td>
        <td class="line">الختم</td>
        <td></td>
        <td class="line">العميل: {{ $clientName ?? '—' }}</td>
    </tr>
</table>
