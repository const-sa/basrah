<?php

namespace Tests\Unit;

use App\Support\TermsClauses;
use PHPUnit\Framework\TestCase;

class TermsClausesTest extends TestCase
{
    public function test_running_terms_are_split_into_numbered_clauses(): void
    {
        $split = TermsClauses::split('اتفق الطرفان على الشروط التالية: 1 ـ تعتبر المقدمة جزءًا. 2 ـ يدفع مبلغ 500 ريال. 3 ـ الدخول الساعة 12 ظهرًا.');

        $this->assertSame('اتفق الطرفان على الشروط التالية:', $split['intro']);
        $this->assertSame([1, 2, 3], array_column($split['clauses'], 'number'));
        $this->assertSame('يدفع مبلغ 500 ريال.', $split['clauses'][1]['text']);
        $this->assertSame('الدخول الساعة 12 ظهرًا.', $split['clauses'][2]['text']);
    }

    public function test_a_number_inside_a_clause_does_not_start_a_new_one(): void
    {
        $split = TermsClauses::split('1. تأمين قدره (300.00 ريال) يعاد. 2. الخروج 04:00 ص.');

        $this->assertCount(2, $split['clauses']);
        $this->assertSame('تأمين قدره (300.00 ريال) يعاد.', $split['clauses'][0]['text']);
    }

    public function test_unnumbered_wording_is_left_as_written(): void
    {
        $split = TermsClauses::split('يدفع المبلغ بداية كل شهر.');

        $this->assertSame([], $split['clauses']);
        $this->assertSame('يدفع المبلغ بداية كل شهر.', $split['intro']);
    }
}
