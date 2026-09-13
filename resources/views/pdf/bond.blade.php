{{--
    The receipt voucher, as the screen prints it. Tables, not flex/grid:
    mpdf collapses those into one column. Each line is its own table —
    Arabic label right, dotted rule centre, English label left.
--}}
@php
    $money = fn ($n) => number_format((float) $n, 2, '.', ',');

    // Halalas always two digits — «50», not «5».
    $halalas = str_pad((string) $bond['amount_halalas'], 2, '0', STR_PAD_LEFT);

    // Phone and WhatsApp together, once when they are the same number.
    $phones = array_values(array_unique(array_filter([$issuer['phone'], $issuer['whatsapp']])));

    $blank = "\u{00A0}";
@endphp

<style>
    body { font-family: xbriyaz, sans-serif; color: #000; font-size: 10pt; line-height: 1.5; }
    table { width: 100%; border-collapse: collapse; }
    .num { direction: ltr; }
    .en { font-style: italic; font-weight: bold; direction: ltr; }

    /* Letterhead: identity right, emblem centre, contacts left. */
    .head td { vertical-align: middle; padding-bottom: 4pt; }
    .head .org { font-size: 15pt; font-weight: bold; line-height: 1.2; }
    .head .sub { font-size: 9pt; font-weight: bold; color: #333; }
    .head .tel { font-size: 11pt; font-weight: bold; direction: ltr; }
    .head .mid { text-align: center; width: 25%; }
    .head .left { text-align: left; direction: ltr; font-size: 9.5pt; font-weight: bold; }

    /* The frame — the voucher body, as on the pad. */
    .frame { border: 1.2pt solid #000; padding: 8pt; }

    .top { border-bottom: 0.8pt solid #000; }
    .top td { vertical-align: top; padding-bottom: 6pt; }

    /* The amount boxes: a label above each ruled box. */
    .amt { width: auto; }
    .amt td { text-align: center; padding: 0 2pt; }
    .amt .lbl { font-size: 9.5pt; font-weight: bold; padding-bottom: 2pt; }
    .amt .box { border: 1pt solid #000; font-size: 14pt; font-weight: bold; direction: ltr; padding: 4pt 2pt; }
    .amt .riyals { width: 42mm; }
    .amt .halalas { width: 13mm; }

    .title { text-align: center; }
    .title .word { font-size: 17pt; font-weight: bold; letter-spacing: 3pt; border-bottom: 1.2pt solid #000; padding: 0 6pt 2pt; }
    .title .lat { font-size: 10pt; margin-top: 3pt; }
    .title .no { font-size: 9pt; font-weight: bold; direction: ltr; margin-top: 2pt; }

    /* Both dates: Hijri and what it falls on, each on its dotted rule. */
    .dates { width: 100%; }
    .dates td { font-size: 10pt; font-weight: bold; padding-bottom: 3pt; }
    .dates td.k { width: 18mm; }
    .dates td.v { border-bottom: 0.5pt dotted #000; text-align: center; direction: ltr; }

    /*
        One table per line, so a label is only as wide as its own text.

        No width on the label cells: mpdf takes «width: 1%» literally and
        breaks the text a character per line despite nowrap, growing the
        row until the voucher spills onto a second page.
    */
    table.ln { margin-top: 7pt; }
    table.ln td { vertical-align: bottom; font-size: 10pt; padding-bottom: 1pt; }
    table.ln td.k { font-weight: bold; white-space: nowrap; padding-left: 4pt; }
    table.ln td.v { border-bottom: 0.5pt dotted #000; text-align: center; font-weight: bold; }
    table.ln td.e { white-space: nowrap; padding-right: 4pt; text-align: left; }
    td.tick { white-space: nowrap; font-weight: bold; padding-left: 6pt; }
    td.tick span { border: 0.7pt solid #000; padding: 0 3pt; font-size: 9pt; }

    /* Signatures stay one block: a signature split from its name proves nothing. */
    .sign { margin-top: 14pt; text-align: center; }
    .sign td { width: 33.33%; vertical-align: top; font-size: 10pt; }
    .sign .role { font-weight: bold; }
    .sign .rule { border-top: 0.5pt dotted #000; margin: 0 auto; padding-top: 2pt; width: 80%; font-size: 8.5pt; font-weight: bold; color: #333; }

    .addr { text-align: center; font-size: 10pt; font-weight: bold; margin-top: 6pt; }
</style>

<table class="head">
    <tr>
        <td>
            <div class="org">{{ $bond['unit_name'] ?? $issuer['business_name'] }}</div>
            @if ($bond['unit_name'])
                <div class="sub">{{ $issuer['business_name'] }}</div>
            @endif
            @if ($phones)
                <div class="tel">{{ implode(' - ', $phones) }}</div>
            @endif
        </td>
        <td class="mid">
            @if ($logoPath)
                <img src="{{ $logoPath }}" style="height: 55px;" alt="">
            @endif
        </td>
        <td class="left">
            @if ($issuer['email'])
                <div>{{ $issuer['email'] }}</div>
            @endif
            @if ($issuer['tax_number'])
                <div>VAT {{ $issuer['tax_number'] }}</div>
            @endif
            @if ($bond['unit_code'])
                <div>{{ $bond['unit_code'] }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="frame">
    {{-- First row: amount box · title · date --}}
    <table class="top">
        <tr>
            <td style="width: 33%;">
                <table class="amt">
                    <tr>
                        <td class="lbl">ريال . <span class="num">S.R</span></td>
                        <td class="lbl">هـ <span class="num">H.</span></td>
                    </tr>
                    <tr>
                        <td><div class="box riyals">{{ number_format($bond['amount_riyals']) }}</div></td>
                        <td><div class="box halalas">{{ $halalas }}</div></td>
                    </tr>
                </table>
            </td>
            <td class="title" style="width: 30%;">
                <div><span class="word">سند قبض</span></div>
                <div class="lat en">Receipt Voucher</div>
                <div class="no">No. {{ $bond['receipt_number'] }}</div>
            </td>
            <td style="width: 37%;">
                <table class="dates">
                    <tr>
                        <td class="k">التاريخ</td>
                        <td class="v">{{ $bond['issued_on_hijri'] ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td class="k">الموافق</td>
                        <td class="v">{{ $bond['issued_on'] ?? '—' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- The voucher lines --}}
    <table class="ln">
        <tr>
            <td class="k">استلمنا من المكرم /</td>
            <td class="v">
                {{ $bond['client_name'] ?? '—' }}
                @if ($bond['client_mobile'])
                    <span class="num">{{ $bond['client_mobile'] }}</span>
                @endif
            </td>
            <td class="e en">Received From</td>
        </tr>
    </table>

    <table class="ln">
        <tr>
            <td class="k">مبلغ وقدره</td>
            <td class="v">{{ $bond['amount_words'] }}</td>
            <td class="e en">The Sum Of</td>
        </tr>
    </table>

    {{-- Method row: the ticked box is how the money actually came in --}}
    <table class="ln">
        <tr>
            <td class="tick"><span>{{ $bond['method_kind'] === 'cash' ? 'X' : $blank }}</span> نقدًا</td>
            <td class="tick"><span>{{ $bond['method_kind'] === 'bank' ? 'X' : $blank }}</span> شيك / حوالة رقم</td>
            <td class="v" style="width: 20mm;">{{ $bond['payment_reference'] ?: $blank }}</td>
            <td class="k" style="padding-right: 4pt;">على بنك</td>
            <td class="v">{{ $bond['method_label'] }}</td>
            <td class="e en">Cash / Cheque No. / Bank</td>
        </tr>
    </table>

    <table class="ln">
        <tr>
            <td class="k">وذلك قيمة</td>
            <td class="v">{{ $bond['paid_for'] }}</td>
            <td class="e en">For</td>
        </tr>
    </table>

    {{--
        The pad's run-on line carries the booking total and what is left:
        the voucher receipts what was taken, and the balance is stated for
        the client, not demanded on this sheet.
    --}}
    <table class="ln">
        <tr>
            <td class="v">
                إجمالي الحجز {{ $money($bond['total_amount']) }} ريال — المتبقي {{ $money($bond['remaining_amount']) }} ريال
            </td>
        </tr>
    </table>

    {{-- Signatures --}}
    <table class="sign">
        <tr>
            <td>
                <div class="role">المستلم</div>
                <div class="en" style="font-size: 8.5pt;">Received By</div>
                <div class="rule" style="margin-top: 24pt;">{{ $bond['created_by'] ?: $blank }}</div>
            </td>
            <td>
                <div class="role">الختم</div>
                <div class="en" style="font-size: 8.5pt;">Seal</div>
                @if ($stampPath)
                    <img src="{{ $stampPath }}" style="height: 48px;" alt="">
                    <div class="rule">{{ $blank }}</div>
                @else
                    <div class="rule" style="margin-top: 24pt;">{{ $blank }}</div>
                @endif
            </td>
            <td>
                <div class="role">المدير</div>
                <div class="en" style="font-size: 8.5pt;">Manager</div>
                @if ($signaturePath)
                    <img src="{{ $signaturePath }}" style="height: 48px;" alt="">
                    <div class="rule">{{ $issuer['manager_name'] ?: $blank }}</div>
                @else
                    <div class="rule" style="margin-top: 24pt;">{{ $issuer['manager_name'] ?: $blank }}</div>
                @endif
            </td>
        </tr>
    </table>
</div>

@if ($issuer['address'])
    <div class="addr">{{ $issuer['address'] }}</div>
@endif
