<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Bonus;
use App\Models\Booking;
use App\Models\City;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\EventType;
use App\Models\Facility;
use App\Models\Item;
use App\Models\MeasureUnit;
use App\Models\NotificationTemplate;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\UnitSection;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * الأرشيف: ما حُذف من النظام ولم يُفقد.
 *
 * الحذف من الشاشات حذفٌ ناعم — يرفع السجل من الاستعمال ويُبقيه في قاعدة
 * البيانات. وهذا الصنف هو دليل الأرشيف: أي الأنواع تُؤرشف، وكيف يُسمّى
 * السجل المؤرشف حين يُعرض للمستخدم الذي لا يعرف أرقام المفاتيح.
 *
 * إضافة نوع إلى الأرشيف سطرٌ واحد هنا + الترايت SoftDeletes على نموذجه
 * + عمود deleted_at في جدوله.
 */
class Archive
{
    /**
     * الأنواع المؤرشفة: المفتاح في المسار => النموذج واسمه العربي ومجموعته.
     *
     * المفتاح نصٌّ ثابت لا اسم صنف، حتى لا يمرّ اسم صنف من المتصفح إلى
     * الخادم فيصير أي نموذج في المشروع هدفًا لاسترجاع أو حذف نهائي.
     *
     * @var array<string, array{model: class-string<Model>, label: string, group: string}>
     */
    public const TYPES = [
        'bookings' => ['model' => Booking::class, 'label' => 'حجز', 'group' => 'الحجوزات'],
        'units' => ['model' => Unit::class, 'label' => 'وحدة', 'group' => 'الحجوزات'],
        'unit-sections' => ['model' => UnitSection::class, 'label' => 'قسم وحدة', 'group' => 'الحجوزات'],
        'facilities' => ['model' => Facility::class, 'label' => 'مرفق', 'group' => 'الحجوزات'],
        'packages' => ['model' => Package::class, 'label' => 'باقة', 'group' => 'الحجوزات'],
        'event-types' => ['model' => EventType::class, 'label' => 'نوع مناسبة', 'group' => 'الحجوزات'],

        'contracts' => ['model' => Contract::class, 'label' => 'عقد', 'group' => 'العقود'],
        'contract-templates' => ['model' => ContractTemplate::class, 'label' => 'نموذج عقد', 'group' => 'العقود'],
        'notification-templates' => ['model' => NotificationTemplate::class, 'label' => 'قالب إشعار', 'group' => 'العقود'],

        'sales' => ['model' => Sale::class, 'label' => 'فاتورة بيع', 'group' => 'المبيعات والمخزون'],
        'items' => ['model' => Item::class, 'label' => 'صنف', 'group' => 'المبيعات والمخزون'],
        'measure-units' => ['model' => MeasureUnit::class, 'label' => 'وحدة قياس', 'group' => 'المبيعات والمخزون'],

        'vouchers' => ['model' => Voucher::class, 'label' => 'سند', 'group' => 'المحاسبة'],
        'accounts' => ['model' => Account::class, 'label' => 'حساب', 'group' => 'المحاسبة'],
        'payment-methods' => ['model' => PaymentMethod::class, 'label' => 'طريقة دفع', 'group' => 'المحاسبة'],

        'employees' => ['model' => Employee::class, 'label' => 'ملف موظف', 'group' => 'الموارد البشرية'],
        'employee-groups' => ['model' => EmployeeGroup::class, 'label' => 'مجموعة موظفين', 'group' => 'الموارد البشرية'],
        'bonuses' => ['model' => Bonus::class, 'label' => 'مكافأة', 'group' => 'الموارد البشرية'],

        'clients' => ['model' => Client::class, 'label' => 'عميل', 'group' => 'العملاء والموردون'],
        'suppliers' => ['model' => Supplier::class, 'label' => 'مورّد', 'group' => 'العملاء والموردون'],
        'tickets' => ['model' => Ticket::class, 'label' => 'تذكرة دعم', 'group' => 'العملاء والموردون'],

        'users' => ['model' => User::class, 'label' => 'مستخدم', 'group' => 'الإدارة والنظام'],
        'roles' => ['model' => Role::class, 'label' => 'دور وصلاحيات', 'group' => 'الإدارة والنظام'],
        'departments' => ['model' => Department::class, 'label' => 'قسم إداري', 'group' => 'الإدارة والنظام'],
        'cities' => ['model' => City::class, 'label' => 'مدينة', 'group' => 'الإدارة والنظام'],
    ];

    /**
     * الأعمدة التي قد تحمل اسم السجل — بترتيب الأولوية في العرض والبحث.
     *
     * @var list<string>
     */
    public const NAME_COLUMNS = ['reference', 'number', 'name', 'title', 'code', 'employee_no'];

    /**
     * استعلام المحذوف من نوعٍ واحد.
     *
     * @return Builder<Model>
     */
    public static function query(string $type): Builder
    {
        $model = self::model($type);

        return $model::query()->onlyTrashed();
    }

    /**
     * @return class-string<Model>
     */
    public static function model(string $type): string
    {
        return self::TYPES[$type]['model']
            ?? throw new \InvalidArgumentException("نوع غير مؤرشف: {$type}");
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    /**
     * المفتاح المقابل لصنف نموذج — للربط بين السجل الرقابي والأرشيف.
     */
    public static function typeOf(string $class): ?string
    {
        foreach (self::TYPES as $type => $meta) {
            if ($meta['model'] === $class) {
                return $type;
            }
        }

        return null;
    }

    /**
     * اسم السجل كما يفهمه المستخدم: «حجز a-12» لا «Booking#12».
     */
    public static function nameOf(Model $record): string
    {
        foreach (self::NAME_COLUMNS as $column) {
            $value = $record->getAttribute($column);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.$record->getKey();
    }

    /**
     * أعمدة الاسم الموجودة فعلًا في جدول النوع — البحث يقتصر عليها.
     *
     * @return list<string>
     */
    public static function searchableColumns(string $type): array
    {
        $model = new (self::model($type));
        $columns = Schema::getColumnListing($model->getTable());

        return array_values(array_intersect(
            [...self::NAME_COLUMNS, 'mobile', 'email'],
            $columns,
        ));
    }
}
