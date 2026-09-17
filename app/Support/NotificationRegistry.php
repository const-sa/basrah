<?php

namespace App\Support;

use App\Models\Advance;
use App\Models\Backup;
use App\Models\Bonus;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\Item;
use App\Models\Leave;
use App\Models\Payroll;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Model;

/**
 * What raises a notice, what it is called, who hears it, and where it opens.
 *
 * One table rather than a Notification class per event: the wording of thirty
 * notices is a list to be read down and corrected, not thirty files. It sits
 * beside AuditLog::SUBJECTS on purpose — that one answers "what is recorded",
 * this one "what is announced", and the two are not the same question.
 */
class NotificationRegistry
{
    /** The shelves of the inbox, as the filter shows them. */
    public const CATEGORIES = [
        'bookings' => 'الحجوزات',
        'clients' => 'العملاء',
        'finance' => 'المالية',
        'sales' => 'المبيعات والمخزون',
        'hr' => 'الموارد البشرية',
        'support' => 'الدعم الفني',
        'system' => 'النظام',
    ];

    /** How loud the notice is. */
    public const LEVELS = [
        'success' => 'إنجاز',
        'info' => 'معلومة',
        'warning' => 'تنبيه',
        'danger' => 'خطر',
    ];

    /** The verb, as it heads the notice. Only "created" needs agreement. */
    private const VERBS = [
        'updated' => 'تعديل',
        'deleted' => 'حذف',
        'restored' => 'استرجاع',
    ];

    /**
     * Model → what it announces.
     *
     * `hears` is a list of permission keys, any one of which is enough: the
     * registers are split per activity, so a booking notice is heard by both
     * the halls' and the chalets' staff, and the unit scope narrows it after.
     *
     * `events` names only the verbs worth announcing. Most records are worth a
     * word when they appear and when they vanish, and nothing in between —
     * every edit of every row would bury the ones that matter.
     *
     * @return array<class-string<Model>, array{category: string, subject: string, feminine?: bool, hears: list<string>, events: array<string, string>}>
     */
    public static function rules(): array
    {
        return [
            Booking::class => [
                'category' => 'bookings',
                'subject' => 'حجز',
                'hears' => ['hall_bookings.view', 'chalet_bookings.view'],
                'events' => ['created' => 'success', 'deleted' => 'warning', 'restored' => 'info'],
            ],
            BookingPayment::class => [
                'category' => 'bookings',
                'subject' => 'دفعة حجز',
                'feminine' => true,
                'hears' => ['hall_bookings.view', 'chalet_bookings.view', 'vouchers.view'],
                'events' => ['created' => 'success'],
            ],
            Contract::class => [
                'category' => 'bookings',
                'subject' => 'عقد',
                'hears' => ['contracts.view', 'hall_contracts.view', 'chalet_contracts.view', 'pool_contracts.view'],
                'events' => ['created' => 'info'],
            ],
            Unit::class => [
                'category' => 'bookings',
                'subject' => 'وحدة',
                'feminine' => true,
                'hears' => ['halls.view', 'chalets.view'],
                'events' => ['created' => 'info', 'deleted' => 'warning'],
            ],

            Client::class => [
                'category' => 'clients',
                'subject' => 'عميل',
                'hears' => ['clients.view', 'hall_clients.view', 'chalet_clients.view', 'pool_clients.view'],
                'events' => ['created' => 'success', 'deleted' => 'warning'],
            ],

            Expense::class => [
                'category' => 'finance',
                'subject' => 'مصروف',
                'hears' => ['expenses.view', 'hall_expenses.view', 'chalet_expenses.view', 'pool_expenses.view'],
                'events' => ['created' => 'info', 'deleted' => 'warning'],
            ],
            Voucher::class => [
                'category' => 'finance',
                'subject' => 'سند',
                'hears' => ['vouchers.view'],
                'events' => ['created' => 'info', 'deleted' => 'warning'],
            ],
            FixedAsset::class => [
                'category' => 'finance',
                'subject' => 'أصل ثابت',
                'hears' => ['fixed_assets.view'],
                'events' => ['created' => 'info'],
            ],

            Sale::class => [
                'category' => 'sales',
                'subject' => 'فاتورة بيع',
                'feminine' => true,
                'hears' => ['sales.view'],
                'events' => ['created' => 'success', 'deleted' => 'warning'],
            ],
            Purchase::class => [
                'category' => 'sales',
                'subject' => 'فاتورة شراء',
                'feminine' => true,
                'hears' => ['purchases.view'],
                'events' => ['created' => 'info', 'deleted' => 'warning'],
            ],
            Quotation::class => [
                'category' => 'sales',
                'subject' => 'عرض سعر',
                'hears' => ['quotations.view'],
                'events' => ['created' => 'info'],
            ],
            Item::class => [
                'category' => 'sales',
                'subject' => 'صنف',
                'hears' => ['items.view'],
                'events' => ['created' => 'info'],
            ],
            Supplier::class => [
                'category' => 'sales',
                'subject' => 'مورّد',
                'hears' => ['suppliers.view'],
                'events' => ['created' => 'info'],
            ],

            Employee::class => [
                'category' => 'hr',
                'subject' => 'ملف موظف',
                'hears' => ['staff.view'],
                'events' => ['created' => 'success', 'deleted' => 'warning'],
            ],
            Payroll::class => [
                'category' => 'hr',
                'subject' => 'مسيّر رواتب',
                'hears' => ['payroll.view'],
                'events' => ['created' => 'info', 'updated' => 'info'],
            ],
            Leave::class => [
                'category' => 'hr',
                'subject' => 'إجازة',
                'feminine' => true,
                'hears' => ['leaves.view'],
                'events' => ['created' => 'info', 'updated' => 'info'],
            ],
            Advance::class => [
                'category' => 'hr',
                'subject' => 'سلفة',
                'feminine' => true,
                'hears' => ['advances.view', 'payroll.view'],
                'events' => ['created' => 'info'],
            ],
            Bonus::class => [
                'category' => 'hr',
                'subject' => 'مكافأة',
                'feminine' => true,
                'hears' => ['bonuses.view', 'payroll.view'],
                'events' => ['created' => 'info'],
            ],

            Ticket::class => [
                'category' => 'support',
                'subject' => 'تذكرة دعم',
                'feminine' => true,
                'hears' => ['tickets.view'],
                'events' => ['created' => 'warning', 'updated' => 'info'],
            ],

            User::class => [
                'category' => 'system',
                'subject' => 'مستخدم',
                'hears' => ['employees.view'],
                'events' => ['created' => 'info', 'deleted' => 'warning'],
            ],
            Role::class => [
                'category' => 'system',
                'subject' => 'مجموعة صلاحيات',
                'feminine' => true,
                'hears' => ['roles.view'],
                'events' => ['created' => 'info', 'updated' => 'warning', 'deleted' => 'warning'],
            ],
            Setting::class => [
                'category' => 'system',
                'subject' => 'الإعدادات العامة',
                'feminine' => true,
                'hears' => ['settings.view'],
                'events' => ['updated' => 'warning'],
            ],
            Backup::class => [
                'category' => 'system',
                'subject' => 'نسخة احتياطية',
                'feminine' => true,
                'hears' => ['backups.view'],
                'events' => ['created' => 'info'],
            ],
        ];
    }

    /**
     * Columns whose change alone is not news.
     *
     * The WhatsApp screen re-stamps the connection time on every status poll,
     * and without this the settings would announce themselves once a second to
     * everyone holding the key.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const IGNORED = [
        Setting::class => ['wa_connected_at'],
    ];

    /** @return list<string> */
    public static function ignored(string $model): array
    {
        return self::IGNORED[$model] ?? [];
    }

    /** The models worth observing — the observer is registered on these alone. */
    public static function models(): array
    {
        return array_keys(static::rules());
    }

    /** @return array<string, mixed>|null */
    public static function for(string $model): ?array
    {
        return static::rules()[$model] ?? null;
    }

    public static function categoryLabel(?string $key): string
    {
        return self::CATEGORIES[$key] ?? (string) $key;
    }

    public static function levelLabel(?string $key): string
    {
        return self::LEVELS[$key] ?? (string) $key;
    }

    /**
     * The headline: "حجز جديد", "تعديل الإعدادات العامة", "حذف عميل".
     */
    public static function title(array $rule, string $verb): string
    {
        if ($verb === 'created') {
            return $rule['subject'].' '.(($rule['feminine'] ?? false) ? 'جديدة' : 'جديد');
        }

        return (self::VERBS[$verb] ?? $verb).' '.$rule['subject'];
    }

    /**
     * The line under the headline — what the record actually is.
     *
     * A notice reading "حجز جديد" and nothing more sends the reader to the
     * register to find out which one, so each record says its own name and the
     * one number that decides whether it is worth opening.
     */
    public static function describe(Model $record): ?string
    {
        $parts = match (true) {
            $record instanceof Booking => [
                $record->reference,
                $record->unit?->name,
                $record->client?->name,
                self::money($record->total_amount),
            ],
            $record instanceof BookingPayment => [
                self::money($record->amount),
                $record->booking?->reference,
                $record->booking?->client?->name,
            ],
            $record instanceof Contract => [$record->number, $record->client?->name],
            $record instanceof Client => [$record->name, $record->mobile],
            $record instanceof Expense => [
                $record->number,
                self::money($record->amount),
                $record->category?->name,
            ],
            $record instanceof Voucher => [
                $record->number,
                Voucher::TYPES[$record->type] ?? $record->type,
                self::money($record->amount),
            ],
            $record instanceof Sale, $record instanceof Purchase => [
                $record->number,
                self::money($record->total_amount),
            ],
            $record instanceof Quotation => [
                $record->number,
                $record->client?->name,
                self::money($record->total_amount),
            ],
            $record instanceof FixedAsset => [$record->code, $record->name, self::money($record->cost)],
            $record instanceof Payroll => [
                $record->number,
                $record->month ? "{$record->month}/{$record->year}" : null,
                self::money($record->total_net),
            ],
            $record instanceof Leave => [
                $record->employee?->name,
                $record->days ? "{$record->days} يوم" : null,
            ],
            $record instanceof Advance, $record instanceof Bonus => [
                $record->employee?->name,
                self::money($record->amount),
            ],
            $record instanceof Ticket => [$record->number, $record->title],
            $record instanceof Employee, $record instanceof Supplier, $record instanceof Unit => [$record->name],
            $record instanceof Item => [$record->code, $record->name],
            $record instanceof User => [$record->name, $record->role?->name],
            $record instanceof Role => [$record->name],
            $record instanceof Backup => [$record->filename],
            default => [],
        };

        $line = implode(' · ', array_filter(array_map(
            fn ($p) => is_scalar($p) && filled($p) ? trim((string) $p) : null,
            $parts,
        )));

        return $line === '' ? null : mb_substr($line, 0, 180);
    }

    /**
     * Where the notice opens. A booking follows its unit's own register.
     */
    public static function link(Model $record): ?string
    {
        return match (true) {
            $record instanceof Booking => $record->unit?->type === 'chalet'
                ? "/admin/bookings/chalets/{$record->getKey()}/edit"
                : "/admin/bookings/halls/{$record->getKey()}/edit",
            $record instanceof BookingPayment => $record->booking_id
                ? "/admin/bookings/{$record->booking_id}/payments"
                : null,
            $record instanceof Contract => match ($record->booking?->unit?->type) {
                'hall' => '/admin/halls/contracts',
                'chalet' => '/admin/chalets/contracts',
                default => '/admin/pools/contracts',
            },
            $record instanceof Client => "/admin/clients/{$record->getKey()}",
            $record instanceof Unit => '/admin/units',
            $record instanceof Expense => '/admin/accounting/expenses',
            $record instanceof Voucher => '/admin/accounting/vouchers',
            $record instanceof FixedAsset => '/admin/accounting/fixed-assets',
            $record instanceof Sale => "/admin/sales/{$record->getKey()}",
            $record instanceof Purchase => "/admin/purchases/{$record->getKey()}",
            $record instanceof Quotation => "/admin/quotations/{$record->getKey()}",
            $record instanceof Item => '/admin/items',
            $record instanceof Supplier => '/admin/suppliers',
            $record instanceof Employee => '/admin/hr/staff',
            $record instanceof Payroll => '/admin/hr/payroll',
            $record instanceof Leave => '/admin/hr/leaves',
            $record instanceof Advance, $record instanceof Bonus => '/admin/hr/staff',
            $record instanceof Ticket => '/admin/tickets',
            $record instanceof User => '/admin/employees',
            $record instanceof Role => '/admin/groups',
            $record instanceof Setting => '/admin/settings/general',
            $record instanceof Backup => '/admin/backups',
            default => null,
        };
    }

    /**
     * The key the front end reads to pick an icon — the model, stripped.
     */
    public static function event(Model $record, string $verb): string
    {
        return mb_strtolower(class_basename($record)).'.'.$verb;
    }

    private static function money(mixed $amount): ?string
    {
        return is_numeric($amount) ? number_format((float) $amount, 2) : null;
    }
}
