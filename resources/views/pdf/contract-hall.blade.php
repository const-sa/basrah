{{--
    عقد إيجار — the halls' printed pad: the hall's name and the accountant's
    number across the head, the serial in its box, the tenant's card copied on
    dotted runs, then the fourteen conditions and the two signatures.

    Tables, not flex/grid: mpdf's layout engine collapses modern CSS into a
    single column, and every cell carries an explicit width for the same reason.
--}}
@php
    // A blank value stays empty — its dotted underline is the fill-in run.
    $fill = fn ($value) => filled($value) ? $value : "\u{00A0}";
@endphp

<style>
    body { font-family: xbriyaz, sans-serif; color: #16215b; font-size: 10pt; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; }
    .num { direction: ltr; unicode-bidi: embed; }
    .rtl { direction: rtl; unicode-bidi: embed; }

    /* Letterhead: the hall right, its emblem centre, the accountant left. */
    .head td { vertical-align: top; }
    .head .hall { font-size: 17pt; font-weight: bold; color: #16215b; line-height: 1.2; }
    .head .tel { font-size: 12pt; font-weight: bold; color: #16215b; }
    .head .who { font-size: 11pt; font-weight: bold; color: #16215b; }

    /* The title in its rule, the serial beside it in red, as the pad prints. */
    .banner { margin: 6pt 0 4pt; border-top: 1.2pt solid #16215b; border-bottom: 1.2pt solid #16215b; padding: 3pt 0; }
    .banner td { vertical-align: middle; }
    .titlebox { font-size: 15pt; font-weight: bold; letter-spacing: 1pt; }
    .serial { font-size: 13pt; font-weight: bold; color: #c8102e; }

    .lead { margin: 4pt 0 2pt; font-size: 10.5pt; font-weight: bold; }

    /* One table per LINE so each label is only as wide as its own text. */
    table.ln { margin: 0; }
    table.ln td { padding: 3pt 1pt 1pt; vertical-align: bottom; font-size: 10pt; }
    table.ln td.k { font-weight: bold; padding-left: 4pt; }
    table.ln td.v { border-bottom: 0.5pt dotted #6b6b6b; }

    /* The two section boxes and the day box, as the paper rules them. */
    .boxes { margin-top: 4pt; }
    .boxes td { vertical-align: middle; font-size: 10pt; font-weight: bold; }
    .tick { border: 0.8pt solid #16215b; padding: 2pt 8pt; text-align: center; }
    .daybox { border: 0.8pt solid #16215b; border-radius: 10pt; padding: 3pt 18pt; text-align: center; }

    .terms { margin-top: 7pt; font-size: 9pt; line-height: 1.7; white-space: pre-wrap; text-align: justify; }

    /* Signatures stay one block: a signature split from its name proves nothing. */
    .sign { margin-top: 10pt; }
    .sign td { width: 50%; vertical-align: top; padding: 0 10pt 0 0; }
    .read { margin-top: 4pt; text-align: left; font-size: 9.5pt; font-weight: bold; }

    .band { margin-top: 8pt; background: #1b3a93; color: #ffffff; text-align: center;
            padding: 4pt 8pt; font-size: 9pt; font-weight: bold; }
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
        <td style="width: 30%;"><span class="serial num">{{ $contract->number }}</span></td>
        <td style="width: 40%; text-align: center;"><span class="titlebox">عقد إيجار</span></td>
        <td style="width: 30%;"></td>
    </tr>
</table>

<div class="lead">تم الاتفاق بين {{ $issuer['business_name'] }} للاحتفالات والمناسبات</div>

<table class="ln"><tr>
    <td class="k" style="width: 20%;">الطرف الثاني المستأجر/</td>
    <td class="v" style="width: 46%;">{{ $fill($contract->client?->name ?? ($data['client_name'] ?? null)) }}</td>
    <td class="k" style="width: 10%; padding-right: 8pt;">سجل مدني</td>
    <td class="v num" style="width: 24%;">{{ $fill($data['client_id_number'] ?? null) }}</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 11%;">مكان الميلاد</td>
    <td class="v" style="width: 43%;">{{ $fill($data['client_birth_place'] ?? null) }}</td>
    <td class="k" style="width: 10%; padding-right: 8pt;">جوال رقم</td>
    <td class="v num" style="width: 36%;">{{ $fill($contract->client?->mobile ?? ($data['client_mobile'] ?? null)) }}</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 11%;">تاريخ الدخول</td>
    <td class="v num" style="width: 21%;">{{ $fill($data['booking_date'] ?? null) }}</td>
    <td class="k" style="width: 6%;">الموافق</td>
    <td class="v num" style="width: 18%;">{{ $fill($data['booking_date_hijri'] ?? null) }} هـ</td>
    <td class="k" style="width: 10%; padding-right: 8pt;">قيمة الإيجار</td>
    <td class="v" style="width: 16%;"><b class="num">{{ $fill($data['total_amount'] ?? null) }}</b></td>
    <td class="k" style="width: 6%; padding-right: 8pt;">واصل</td>
    <td class="v" style="width: 12%;"><b class="num">{{ $fill($data['deposit_amount'] ?? null) }}</b></td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 8%;">باقي</td>
    <td class="v" style="width: 20%;"><b class="num">{{ $fill($data['remaining_amount'] ?? null) }}</b></td>
    <td class="k" style="width: 10%; padding-right: 8pt;">عدد الضيوف</td>
    <td class="v" style="width: 14%;">{{ $fill($data['guests_count'] ?? null) }}</td>
    <td class="k" style="width: 10%; padding-right: 8pt;">وقت الدخول</td>
    <td class="v" style="width: 38%;">{{ $fill($data['check_in_time'] ?? null) }}</td>
</tr></table>

<table class="boxes">
    <tr>
        <td style="width: 12%;">قسم رقم :</td>
        <td class="tick" style="width: 26%;">{{ $fill($data['sections'] ?? null) }}</td>
        <td style="width: 22%;"></td>
        <td style="width: 4%;"></td>
        <td class="daybox" style="width: 20%;">{{ $fill($data['check_in_day'] ?? null) }}</td>
        <td style="width: 16%;"></td>
    </tr>
</table>

{{-- الشروط تمتدّ على ما تحتاجه من أوراق، فلا تُقيَّد بكتلة واحدة --}}
@if ($terms)
    <div class="terms">{{ $terms }}</div>
@endif

<table class="sign">
    <tr>
        <td>
            <table class="ln"><tr>
                <td class="k" style="width: 32%;">الطرف الأول المؤجر :</td>
                <td class="v" style="width: 68%;">{{ $fill($issuer['manager_name'] ?? $issuer['business_name']) }}</td>
            </tr></table>
            <table class="ln"><tr>
                <td class="k" style="width: 32%;">التوقيع :</td>
                <td class="v" style="width: 68%;">
                    @if ($signaturePath)<img src="{{ $signaturePath }}" style="max-height: 26pt;" alt="">@endif
                    @if ($stampPath)<img src="{{ $stampPath }}" style="max-height: 26pt; margin-right: 8pt;" alt="">@endif
                    @if (! $signaturePath && ! $stampPath){{ $fill(null) }}@endif
                </td>
            </tr></table>
        </td>
        <td>
            <table class="ln"><tr>
                <td class="k" style="width: 34%;">الطرف الثاني المستأجر :</td>
                <td class="v" style="width: 66%;">{{ $fill($contract->client?->name ?? ($data['client_name'] ?? null)) }}</td>
            </tr></table>
            <table class="ln"><tr>
                <td class="k" style="width: 34%;">التوقيع :</td>
                <td class="v" style="width: 66%;">{{ $fill(null) }}</td>
            </tr></table>
            <div class="read">التوقيع بالقراءة والعلم</div>
        </td>
    </tr>
</table>

@if ($issuer['address'])
    <div class="band">{{ $issuer['address'] }}</div>
@endif
