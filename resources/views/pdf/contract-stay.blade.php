{{--
    The chalet's daily rental, laid out as the printed pad it replaces. Same
    data as pdf/contract.blade.php; tables, not flex/grid, because mpdf's
    layout engine collapses modern CSS into a single column.
--}}
@php
    // Every cell carries an explicit width: mpdf ignores white-space:nowrap, so
    // an unsized label column collapses and wraps one letter per line.
    // A blank value stays empty — its dotted underline is the fill-in run.
    $fill = fn ($value) => filled($value) ? $value : "\u{00A0}";
@endphp

<style>
    body { font-family: xbriyaz, sans-serif; color: #141414; font-size: 10pt; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; }
    .num { direction: ltr; unicode-bidi: embed; }
    .rtl { direction: rtl; unicode-bidi: embed; }

    .basmala { text-align: center; font-size: 8.5pt; color: #8a8a8a; margin-bottom: 3pt; }

    /* Letterhead: name and phones right, emblem centre, date box left. */
    .head td { vertical-align: top; }
    .head .biz { font-size: 20pt; font-weight: bold; line-height: 1.15; color: #c8102e; }
    .head .tel { font-size: 10pt; font-weight: bold; color: #c8102e; }
    .datebox { border: 0.8pt solid #141414; }
    .datebox td { border: 0.4pt solid #9a9a9a; padding: 2pt 4pt; font-size: 8.5pt; }
    .datebox .k { width: 36%; font-weight: bold; }
    .datebox .v { text-align: center; }

    /* The title and the serial sit on one line, each in its own small box. */
    .banner { margin: 7pt 0 5pt; }
    .banner td { vertical-align: middle; }
    .titlebox { border: 1pt solid #141414; padding: 4pt 16pt; font-size: 13pt; font-weight: bold; }
    .nobox { border: 1pt solid #141414; padding: 4pt 9pt; font-size: 10.5pt; font-weight: bold; }

    .lead { margin: 0 0 1pt; font-size: 10.5pt; font-weight: bold; }

    /* One table per LINE so each label is only as wide as its own text
       instead of every row sharing one column grid. */
    table.ln { margin: 0; }
    table.ln td { padding: 2.5pt 1pt 1pt; vertical-align: bottom; font-size: 10pt; }
    table.ln td.k { font-weight: bold; padding-left: 4pt; }
    table.ln td.g { font-weight: bold; padding: 2.5pt 8pt 1pt 4pt; }
    table.ln td.v { border-bottom: 0.5pt dotted #6b6b6b; }
    table.ln.tight td { font-size: 8.5pt; }

    .terms { font-size: 8.5pt; line-height: 1.5; white-space: pre-wrap; text-align: justify; margin-top: 7pt; }

    .note { margin-top: 7pt; }
    .note .lbl { font-size: 10pt; font-weight: bold; }
    .note .line { border-bottom: 0.5pt dotted #6b6b6b; height: 11pt; margin-top: 2pt; }

    /* Signatures stay one block: a signature split from its name proves nothing. */
    .sign { margin-top: 9pt; }
    .sign td.col { width: 50%; vertical-align: top; padding: 0 14pt 0 0; }
    .sign .who { font-size: 13pt; font-weight: bold; color: #c8102e; margin-bottom: 3pt; }

    .foot { margin-top: 10pt; background: #141414; color: #ffffff; text-align: center;
            padding: 4pt 8pt; font-size: 9pt; font-weight: bold; }
</style>

<div class="basmala">بسم الله الرحمن الرحيم</div>

<table class="head">
    <tr>
        <td style="width: 40%;">
            <div class="biz">{{ $unitName ?? $issuer['business_name'] }}</div>
            @if ($issuer['phone'])
                <div class="tel">الإدارة <span class="num">{{ $issuer['phone'] }}</span></div>
            @endif
            @if ($issuer['whatsapp'])
                <div class="tel">واتساب <span class="num">{{ $issuer['whatsapp'] }}</span></div>
            @endif
        </td>
        <td style="width: 22%; text-align: center;">
            @if ($logoPath)
                <img src="{{ $logoPath }}" style="max-height: 52pt; max-width: 100%;" alt="">
            @endif
        </td>
        <td style="width: 38%;">
            <table class="datebox">
                <tr>
                    <td class="k">التاريخ</td>
                    <td class="v num">{{ $data['contract_date'] ?? $contract->created_at?->toDateString() }}</td>
                </tr>
                <tr>
                    <td class="k">الموافق</td>
                    <td class="v num">{{ $fill($data['contract_date_hijri'] ?? null) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="banner">
    <tr>
        <td style="width: 32%;"></td>
        <td style="width: 36%; text-align: center;"><span class="titlebox">عقد إيجار يومي</span></td>
        <td style="width: 32%;"><span class="nobox">No. <span class="num" style="color:#c8102e;">{{ $contract->number }}</span></span></td>
    </tr>
</table>

<div class="lead">تم بعون الله الاتفاق بين:</div>

<table class="ln"><tr>
    <td class="k" style="width: 22%;">الطرف الأول (المؤجر):</td>
    <td class="v" style="width: 78%;"><span class="rtl">{{ $unitName ?? $issuer['business_name'] }}</span></td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 24%;">الطرف الثاني (المستأجر):</td>
    <td class="v" style="width: 76%;">{{ $fill($contract->client?->name ?? ($data['client_name'] ?? null)) }}</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 20%;">رقم البطاقة / الإقامة:</td>
    <td class="v num" style="width: 32%;">{{ $fill($data['client_id_number'] ?? null) }}</td>
    <td class="g" style="width: 8%;">جوال:</td>
    <td class="v num" style="width: 40%;">{{ $fill($contract->client?->mobile ?? ($data['client_mobile'] ?? null)) }}</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 16%;">العنوان الكامل:</td>
    <td class="v" style="width: 84%;">{{ $fill($data['client_address'] ?? null) }}</td>
</tr></table>

<table class="ln" style="margin-top: 3pt;"><tr>
    <td class="k" style="width: 40%;">على أن يستأجر الطرف الثاني من الطرف الأول شاليه رقم:</td>
    {{-- The name is isolated: one ending in a Latin digit otherwise merges into the code. --}}
    <td class="v" style="width: 32%;"><span class="rtl">{{ $unitName ?? '' }}</span>@if ($unitCode) <span class="num">({{ $unitCode }})</span>@endif</td>
    <td class="g" style="width: 8%;">الحجز:</td>
    <td class="v num" style="width: 20%;">{{ $fill($data['booking_reference'] ?? $contract->booking?->reference) }}</td>
</tr></table>

@if (!empty($data['sections']))
    <table class="ln"><tr>
        <td class="k" style="width: 16%;">النطاق المحجوز:</td>
        <td class="v" style="width: 84%;">{{ $data['sections'] }}</td>
    </tr></table>
@endif

<table class="ln tight"><tr>
    <td class="k" style="width: 12%;">تبدأ من يوم</td>
    <td class="v" style="width: 12%;">{{ $fill($data['check_in_day'] ?? null) }}</td>
    <td class="g" style="width: 7%;">بتاريخ</td>
    <td class="v num" style="width: 31%;">{{ $fill($data['booking_date'] ?? null) }} — {{ $fill($data['booking_date_hijri'] ?? null) }}</td>
    <td class="g" style="width: 18%;">وقت الدخول الساعة</td>
    <td class="v" style="width: 20%;">{{ $fill($data['check_in_time'] ?? null) }}</td>
</tr></table>

<table class="ln tight"><tr>
    <td class="k" style="width: 12%;">إلى يوم</td>
    <td class="v" style="width: 12%;">{{ $fill($data['check_out_day'] ?? null) }}</td>
    <td class="g" style="width: 7%;">بتاريخ</td>
    <td class="v num" style="width: 31%;">{{ $fill($data['last_day_date'] ?? null) }} — {{ $fill($data['last_day_date_hijri'] ?? null) }}</td>
    <td class="g" style="width: 18%;">وقت الخروج الساعة</td>
    <td class="v" style="width: 20%;">{{ $fill($data['check_out_time'] ?? null) }}</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 14%;">مدة الإقامة:</td>
    <td class="v" style="width: 30%;">{{ $fill($data['duration_label'] ?? $data['days_count'] ?? null) }}</td>
    <td class="g" style="width: 14%;">عدد الضيوف:</td>
    <td class="v" style="width: 42%;">{{ $fill($data['guests_count'] ?? null) }}</td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 18%;">مبلغ إجمالي وقدره:</td>
    <td class="v" style="width: 82%;">
        <b class="num">{{ $data['total_amount'] ?? '' }}</b> ريال
        @if (!empty($data['total_amount_words']))
            <span style="font-size: 9pt; color: #4a4a4a;">({{ $data['total_amount_words'] }})</span>
        @endif
    </td>
</tr></table>

<table class="ln"><tr>
    <td class="k" style="width: 18%;">العربون المدفوع:</td>
    <td class="v" style="width: 30%;"><b class="num">{{ $data['deposit_amount'] ?? '' }}</b> ريال</td>
    <td class="g" style="width: 12%;">المتبقي:</td>
    <td class="v" style="width: 40%;"><b class="num">{{ $data['remaining_amount'] ?? '' }}</b> ريال</td>
</tr></table>

@if ((float) str_replace(',', '', (string) ($data['security_deposit'] ?? '0')) > 0)
    <table class="ln"><tr>
        <td class="k" style="width: 18%;">التأمين المسترد:</td>
        <td class="v" style="width: 82%;">
            <b class="num">{{ $data['security_deposit'] }}</b> ريال
            <span style="font-size: 9pt; color: #4a4a4a;">— يُعاد كاملًا عند التسليم بلا ملاحظات</span>
        </td>
    </tr></table>
@endif

{{-- Terms run over as many pages as they need, so they are not kept together. --}}
@if ($terms)
    <div class="terms">{{ $terms }}</div>
@endif

<div class="note">
    <span class="lbl">ملاحظة:</span>
    <div class="line"></div>
</div>

<table class="sign">
    <tr>
        <td class="col">
            <div class="who">الإدارة</div>
            <table class="ln"><tr>
                <td class="k" style="width: 20%;">إسم:</td>
                <td class="v" style="width: 80%;">{{ $fill($issuer['manager_name'] ?? $issuer['business_name']) }}</td>
            </tr></table>
            <table class="ln"><tr>
                <td class="k" style="width: 20%;">التوقيع:</td>
                <td class="v" style="width: 80%;">
                    @if ($signaturePath)<img src="{{ $signaturePath }}" style="max-height: 28pt;" alt="">@endif
                    @if ($stampPath)<img src="{{ $stampPath }}" style="max-height: 28pt; margin-right: 8pt;" alt="">@endif
                    @if (! $signaturePath && ! $stampPath){{ $fill(null) }}@endif
                </td>
            </tr></table>
        </td>
        <td class="col">
            <div class="who">المستأجر</div>
            <table class="ln"><tr>
                <td class="k" style="width: 20%;">إسم:</td>
                <td class="v" style="width: 80%;">{{ $fill($contract->client?->name ?? ($data['client_name'] ?? null)) }}</td>
            </tr></table>
            <table class="ln"><tr>
                <td class="k" style="width: 20%;">التوقيع:</td>
                <td class="v" style="width: 80%;">{{ $fill(null) }}</td>
            </tr></table>
        </td>
    </tr>
</table>

<div class="foot">
    @if ($issuer['phone'])<span class="num">{{ $issuer['phone'] }}</span>@endif
    @if ($issuer['phone'] && $issuer['whatsapp']) - @endif
    @if ($issuer['whatsapp'])<span class="num">{{ $issuer['whatsapp'] }}</span>@endif
    @if ($issuer['address']) - {{ $issuer['address'] }}@endif
</div>
