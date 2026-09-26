{{--
    الشروط بنودًا مرقّمة، كل بندٍ في سطره ورقمه في عمود — بدل كتلةٍ واحدة
    لا يُعرف فيها أين ينتهي بند. نصٌّ لا ترقيم فيه يُطبع كما كُتب.

    @param string $terms
    @param string $color  لون الأرقام — لون الورقة التي تُطبع فيها.
    @param bool $compact  ورقةٌ تُطبع في صفحة واحدة (عقد الشاليه) تُضغط بنودها.
--}}
@php
    $split = \App\Support\TermsClauses::split($terms);
    $color ??= '#1e3a8a';
    $compact ??= false;
    $cell = $compact ? 'padding: 0.3pt 0; font-size: 8pt; line-height: 1.4;' : '';
@endphp
<style>
    .clauses { width: 100%; border-collapse: collapse; margin-top: 2pt; }
    .clauses td { vertical-align: top; padding: 1.5pt 0; font-size: 9pt; line-height: 1.6; text-align: justify; }
    .clauses td.n { width: 5%; font-weight: bold; text-align: center; white-space: nowrap; }
    .clauses-intro { font-size: 9pt; font-weight: bold; margin: 0 0 2pt; }
    .clauses-plain { font-size: 9pt; line-height: 1.7; white-space: pre-wrap; text-align: justify; }
</style>

@if ($split['clauses'])
    @if ($split['intro'])
        <div class="clauses-intro">{{ $split['intro'] }}</div>
    @endif
    <table class="clauses">
        @foreach ($split['clauses'] as $clause)
            <tr>
                <td class="n" style="color: {{ $color }}; {{ $cell }}">{{ $clause['number'] }}</td>
                <td style="{{ $cell }}">{{ $clause['text'] }}</td>
            </tr>
        @endforeach
    </table>
@else
    <div class="clauses-plain">{{ $terms }}</div>
@endif
