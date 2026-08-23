<?php

use App\Services\Accounting\Ledger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The refundable security deposit — money held against damage and given back
 * at check-out.
 *
 * It is not the booking deposit. A deposit is part of the price: it comes off
 * the total and turns into revenue once the stay is over. A security deposit
 * is never part of the price — it is held, and goes back whole unless
 * something was damaged. Mixing the two would inflate every booking's revenue
 * by money that was only ever borrowed, so it gets its own column, its own
 * payment types and its own liability account.
 */
return new class extends Migration
{
    private const OLD_TYPES = ['deposit', 'payment', 'refund'];

    private const NEW_TYPES = ['security_deposit', 'security_refund', 'security_forfeit'];

    /** Parent of the liability account the held deposits sit in. */
    private const DEPOSITS_GROUP = '2400';

    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->decimal('security_deposit', 12, 2)->nullable()->after('capacity')
                ->comment('التأمين المعتاد لهذه الوحدة — يُملأ به الحجز ويبقى قابلًا للتعديل');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('security_deposit_amount', 12, 2)->default(0)->after('deposit_amount')
                ->comment('التأمين المتفق عليه — خارج الإجمالي والمتبقي، يُعاد عند الخروج');
        });

        $this->redefineTypes([...self::OLD_TYPES, ...self::NEW_TYPES]);
        $this->addDepositAccounts();
    }

    public function down(): void
    {
        // The posted journal entries stay: dropping the accounts would leave
        // entries pointing at nothing, and rolling back a column is not a
        // reason to deny money that was actually taken.
        DB::table('booking_payments')->whereIn('type', self::NEW_TYPES)->delete();

        $this->redefineTypes(self::OLD_TYPES);

        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('security_deposit_amount'));
        Schema::table('units', fn (Blueprint $table) => $table->dropColumn('security_deposit'));
    }

    /**
     * Redefines the type column textually, as the booking-status migration
     * does — doctrine/dbal does not understand MySQL enum columns.
     *
     * MySQL only: SQLite has no ENUM and accepts any string, so the tests read
     * the same rows without this. What a type may be is enforced in the app
     * (Rule::in on BookingPayment::TYPES), not by the column.
     *
     * @param  list<string>  $types
     */
    private function redefineTypes(array $types): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $values = collect($types)->unique()->map(fn (string $t) => "'{$t}'")->implode(', ');

        DB::statement("ALTER TABLE `booking_payments` MODIFY `type`
            ENUM({$values})
            NOT NULL DEFAULT 'payment'
            COMMENT 'عربون | دفعة | استرداد | تأمين مقبوض | رد تأمين | خصم من التأمين'");
    }

    /**
     * The deposits account goes under liabilities, not under unearned revenue.
     *
     * The unearned-revenue account holds money that will become revenue. A
     * security deposit will not: it leaves the way it came unless it is
     * forfeited, and only the forfeited part ever crosses into revenue. Two
     * liabilities that resolve differently do not belong in one account.
     */
    private function addDepositAccounts(): void
    {
        $now = now();

        DB::table('accounts')->updateOrInsert(
            ['code' => self::DEPOSITS_GROUP],
            [
                'name' => 'تأمينات مستلمة',
                'type' => 'liability',
                'is_group' => true,
                'parent_id' => DB::table('accounts')->where('code', '2000')->value('id'),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('accounts')->updateOrInsert(
            ['code' => Ledger::REFUNDABLE_DEPOSITS],
            [
                'name' => 'تأمينات حجوزات مستردة',
                'type' => 'liability',
                'is_group' => false,
                'parent_id' => DB::table('accounts')->where('code', self::DEPOSITS_GROUP)->value('id'),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }
};
