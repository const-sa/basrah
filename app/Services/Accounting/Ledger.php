<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * محرّك القيود — المنفذ الوحيد لكتابة أي قيد في الدفاتر.
 *
 * كل الأنظمة (حجوزات، كاشير، سندات، رواتب) تمرّ من هنا، فيبقى شرط
 * التوازن وقاعدة «المرحَّل لا يُعدَّل» مطبَّقين في مكان واحد لا في كل متحكم.
 */
class Ledger
{
    /**
     * أكواد الحسابات القياسية التي تعتمد عليها القيود التلقائية.
     * تُنشأ في AccountsSeeder، وتغيير الكود هنا يعني تغييره هناك.
     */
    public const CASH = '1110';

    public const BANK = '1120';

    public const RECEIVABLES = '1210';

    public const INVENTORY = '1310';

    public const PAYABLES = '2110';

    public const UNEARNED_REVENUE = '2210';

    public const SALARIES_PAYABLE = '2310';

    /**
     * Security deposits held against damage. Deliberately not UNEARNED_REVENUE:
     * that account holds money on its way to becoming revenue, and this money
     * is on its way back to the guest. Only a forfeited part ever crosses over.
     */
    public const REFUNDABLE_DEPOSITS = '2410';

    public const BOOKING_REVENUE = '4110';

    public const SALES_REVENUE = '4120';

    public const COGS = '5110';

    public const SALARIES_EXPENSE = '5210';

    public const GENERAL_EXPENSE = '5310';

    /**
     * إنشاء قيد وترحيله في خطوة واحدة.
     *
     * @param  list<array{account: string|int, debit?: float, credit?: float, cost_center_id?: int|null, description?: string|null}>  $lines
     *
     * @throws RuntimeException إذا كان القيد غير متوازن أو بلا سطور
     */
    public function post(
        string $date,
        string $description,
        array $lines,
        string $source = 'manual',
        ?Model $reference = null,
        ?int $userId = null,
    ): JournalEntry {
        return DB::transaction(function () use ($date, $description, $lines, $source, $reference, $userId) {
            $prepared = $this->prepareLines($lines);

            $debit = round(array_sum(array_column($prepared, 'debit')), 2);
            $credit = round(array_sum(array_column($prepared, 'credit')), 2);

            if (abs($debit - $credit) >= 0.01) {
                throw new RuntimeException(
                    "قيد غير متوازن: المدين {$debit} والدائن {$credit} — «{$description}».",
                );
            }

            if ($debit <= 0) {
                throw new RuntimeException("قيد بلا مبالغ — «{$description}».");
            }

            $entry = JournalEntry::create([
                'number' => $this->nextNumber($date),
                'entry_date' => $date,
                'description' => $description,
                'status' => 'posted',
                'source' => $source,
                'total_debit' => $debit,
                'total_credit' => $credit,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            $entry->lines()->createMany($prepared);

            return $entry->load('lines.account');
        });
    }

    /**
     * عكس قيد مرحَّل بقيد مضاد.
     * القيد الأصلي يبقى كما هو — هذا ما يحفظ أثر التدقيق.
     */
    public function reverse(JournalEntry $entry, ?string $reason = null, ?int $userId = null): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw new RuntimeException('لا يُعكَس إلا قيد مرحَّل.');
        }

        if ($entry->reversed_by_entry_id) {
            throw new RuntimeException("القيد {$entry->number} معكوس مسبقًا.");
        }

        return DB::transaction(function () use ($entry, $reason, $userId) {
            $lines = $entry->lines->map(fn ($l) => [
                'account' => $l->account_id,
                'debit' => (float) $l->credit,
                'credit' => (float) $l->debit,
                'cost_center_id' => $l->cost_center_id,
                'description' => $l->description,
            ])->all();

            $reversal = $this->post(
                $entry->entry_date->toDateString(),
                'عكس القيد '.$entry->number.($reason ? " — {$reason}" : ''),
                $lines,
                $entry->source,
                null,
                $userId,
            );

            $entry->update(['status' => 'reversed', 'reversed_by_entry_id' => $reversal->id]);

            return $reversal;
        });
    }

    /**
     * تحويل السطور المختصرة إلى سطور جاهزة، مع قبول كود الحساب أو معرّفه.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function prepareLines(array $lines): array
    {
        $prepared = [];

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            // السطر الصفري لا يُكتب — يلوّث القيد بلا فائدة.
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $prepared[] = [
                'account_id' => $this->accountId($line['account']),
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ];
        }

        return $prepared;
    }

    /**
     * معرّف الحساب من كوده أو من معرّفه مباشرة.
     */
    private function accountId(string|int $account): int
    {
        if (is_int($account)) {
            return $account;
        }

        $id = Account::where('code', $account)->value('id');

        if (! $id) {
            throw new RuntimeException("الحساب بالكود {$account} غير موجود في شجرة الحسابات.");
        }

        return $id;
    }

    /**
     * رقم قيد متسلسل داخل السنة: JV-2026-000001
     */
    private function nextNumber(string $date): string
    {
        $year = substr($date, 0, 4);
        $prefix = "JV-{$year}-";

        $last = JournalEntry::where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
