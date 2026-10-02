<?php

namespace App\Services;

use App\Models\CostCenter;
use App\Models\Item;
use App\Models\StockIssue;
use App\Services\Accounting\Ledger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * إذن الصرف: يُنقص المخزون ويكتب قيده في خطوة واحدة.
 *
 * القيد: مدين حساب المصروف المختار (صيانة مثلًا) — دائن مخزون البضاعة،
 * بتكلفة المواد المصروفة وعلى مركز تكلفة القسم، فيظهر استهلاك المسابح في
 * مصروفاتها لا في تكلفة مبيعاتها.
 */
class StockIssueService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly Ledger $ledger,
    ) {}

    /**
     * @param  array{issue_date: string, department_id?: int|null, employee_id?: int|null, recipient_name?: string|null, contract_id?: int|null, expense_account_id: int, notes?: string|null, items: list<array{item_id: int, quantity: float|string}>}  $data
     */
    public function create(array $data, ?int $userId = null): StockIssue
    {
        return DB::transaction(function () use ($data, $userId) {
            // سطران للصنف نفسه يُجمعان، وإلا فُحص كل منهما على الرصيد وحده
            // فمرّ مجموعهما وهو أكبر من المتاح.
            $wanted = [];
            foreach ($data['items'] as $row) {
                $wanted[(int) $row['item_id']] = ($wanted[(int) $row['item_id']] ?? 0) + (float) $row['quantity'];
            }

            $items = Item::whereIn('id', array_keys($wanted))->get()->keyBy('id');

            foreach ($wanted as $itemId => $qty) {
                $item = $items[$itemId];

                if (! $item->tracksStock()) {
                    throw new RuntimeException("الصنف «{$item->name}» غير مخزني فلا يُصرف من المخزن.");
                }

                $check = $this->inventory->checkAvailability($item, $qty);
                if (! $check['ok']) {
                    throw new RuntimeException($check['reason']);
                }
            }

            $issue = StockIssue::create([
                'number' => $this->nextNumber(),
                'issue_date' => $data['issue_date'],
                'department_id' => $data['department_id'] ?? null,
                'employee_id' => $data['employee_id'] ?? null,
                'recipient_name' => $data['recipient_name'] ?? null,
                'contract_id' => $data['contract_id'] ?? null,
                'expense_account_id' => $data['expense_account_id'],
                'notes' => $data['notes'] ?? null,
                'status' => 'posted',
                'user_id' => $userId,
            ]);

            $total = 0.0;

            foreach ($wanted as $itemId => $qty) {
                $item = $items[$itemId];
                $unitCost = round((float) $item->cost, 2);
                $lineTotal = round($qty * $unitCost, 2);
                $total += $lineTotal;

                $issue->items()->create([
                    'item_id' => $itemId,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineTotal,
                ]);

                $this->inventory->move(
                    $item,
                    -$qty,
                    'issue',
                    $issue,
                    $userId,
                    $unitCost,
                    "إذن صرف {$issue->number}".($issue->recipientLabel() ? " — {$issue->recipientLabel()}" : ''),
                );
            }

            $total = round($total, 2);
            $issue->update(['total_cost' => $total]);

            // مواد بلا تكلفة مسجّلة تُنقص الرصيد ولا تترك أثرًا ماليًا —
            // القيد الصفري يرفضه دفتر الأستاذ.
            if ($total > 0) {
                $costCenter = $this->costCenterFor($issue);

                $entry = $this->ledger->post(
                    $issue->issue_date->toDateString(),
                    "إذن صرف مخزني {$issue->number}".($issue->recipientLabel() ? " — {$issue->recipientLabel()}" : ''),
                    [
                        ['account' => (int) $issue->expense_account_id, 'debit' => $total, 'cost_center_id' => $costCenter],
                        ['account' => Ledger::INVENTORY, 'credit' => $total, 'cost_center_id' => $costCenter],
                    ],
                    'stock_issue',
                    $issue,
                    $userId,
                );

                $issue->update(['journal_entry_id' => $entry->id]);
            }

            return $issue;
        });
    }

    /**
     * إلغاء الإذن: المواد تعود للمخزن بتكلفتها التي خرجت بها، والقيد يُعكس
     * بقيد مضاد — الأصل يبقى في الدفاتر لأثر التدقيق.
     */
    public function cancel(StockIssue $issue, ?int $userId = null, ?string $reason = null): StockIssue
    {
        if ($issue->isCancelled()) {
            throw new RuntimeException("إذن الصرف {$issue->number} ملغى مسبقًا.");
        }

        return DB::transaction(function () use ($issue, $userId, $reason) {
            foreach ($issue->items()->with('item')->get() as $line) {
                if ($line->item) {
                    $this->inventory->move(
                        $line->item,
                        (float) $line->quantity,
                        'issue_revert',
                        $issue,
                        $userId,
                        (float) $line->unit_cost,
                        "إلغاء إذن صرف {$issue->number}".($reason ? " — {$reason}" : ''),
                    );
                }
            }

            if ($issue->journalEntry && $issue->journalEntry->isPosted()) {
                $this->ledger->reverse($issue->journalEntry, "إلغاء إذن صرف {$issue->number}", $userId);
            }

            $issue->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
            ]);

            return $issue;
        });
    }

    /** مركز تكلفة القسم الذي صُرفت له المواد، وإلا المركز العام. */
    public function costCenterFor(StockIssue $issue): int
    {
        if ($issue->department) {
            return CostCenter::forDepartment($issue->department)->id;
        }

        return CostCenter::general()->id;
    }

    private function nextNumber(): string
    {
        $prefix = 'ISS-'.now()->year.'-';

        $last = StockIssue::where('number', 'like', $prefix.'%')->orderByDesc('id')->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
