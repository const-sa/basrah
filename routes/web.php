<?php

use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\BookingsController;
use App\Http\Controllers\Admin\ChaletBookingsController;
use App\Http\Controllers\Admin\ChaletCalendarController;
use App\Http\Controllers\Admin\HallBookingsController;
use App\Http\Controllers\Admin\HallCalendarController;
use App\Http\Controllers\Admin\HallMonthCalendarController;
use App\Http\Controllers\Admin\CitiesController;
use App\Http\Controllers\Admin\ClientsController;
use App\Http\Controllers\Admin\ContractsController;
use App\Http\Controllers\Admin\ContractTemplatesController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EventTypesController;
use App\Http\Controllers\Admin\FacilitiesController;
use App\Http\Controllers\Admin\FinancialReportsController;
use App\Http\Controllers\Admin\HallContractTemplateController;
use App\Http\Controllers\Admin\HrController;
use App\Http\Controllers\Admin\ItemsController;
use App\Http\Controllers\Admin\MeasureUnitsController;
use App\Http\Controllers\Admin\PackagesController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\PricingController;
use App\Http\Controllers\Admin\SalesController;
use App\Http\Controllers\Admin\SuppliersController;
use App\Http\Controllers\Admin\WhatsappLogController;
use App\Http\Controllers\Admin\DemoAccountsController;
use App\Http\Controllers\Admin\DepartmentsController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Controllers\Admin\NotificationTemplatesController;
use App\Http\Controllers\Admin\RolesController;
use App\Http\Controllers\Admin\TicketsController;
use App\Http\Controllers\Admin\UnitsController;
use App\Http\Controllers\Admin\UnitWorkspaceController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\WhatsappSettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::prefix('admin')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->middleware('perm:dashboard.view')->name('dashboard');

    // التقارير (صفحة عامة قابلة للتوسعة)
    Route::get('reports', fn () => Inertia::render('admin/Reports'))->middleware('perm:reports.view')->name('reports.index');

    // فكرة المشروع (صفحة تعريفية عامة لكل مستخدم مسجّل)
    Route::get('about', fn () => Inertia::render('admin/About'))->name('about.index');

    // الإشعارات
    Route::get('notifications', [NotificationsController::class, 'index'])->middleware('perm:notifications.view')->name('notifications.index');

    // مكتبة الإشعارات (قوالب قابلة للإرسال للعملاء)
    Route::get('notifications/library', [NotificationTemplatesController::class, 'index'])->middleware('perm:notifications.view')->name('notifications.library');
    Route::post('notifications/library', [NotificationTemplatesController::class, 'store'])->middleware('perm:notifications.create')->name('notifications.library.store');
    Route::put('notifications/library/{template}', [NotificationTemplatesController::class, 'update'])->middleware('perm:notifications.edit')->name('notifications.library.update');
    Route::delete('notifications/library/{template}', [NotificationTemplatesController::class, 'destroy'])->middleware('perm:notifications.delete')->name('notifications.library.destroy');
    Route::post('notifications/library/{template}/send', [NotificationTemplatesController::class, 'send'])->middleware('perm:notifications.send')->name('notifications.library.send');

    /*
    |--------------------------------------------------------------------------
    | نظام الحجوزات
    |--------------------------------------------------------------------------
    | الوسيط system:bookings يحرس النظام كاملاً، ثم تفصل صلاحية الإجراء داخله.
    */
    Route::middleware('system:bookings')->group(function () {
        // نموذج العقد المعتمد للقاعات — قبل units/{type?} حتى لا يُلتقط كمقطع نوع
        Route::get('units/contract-template', [HallContractTemplateController::class, 'show'])->middleware('perm:units.view')->name('units.contract_template');
        Route::put('units/contract-template', [HallContractTemplateController::class, 'update'])->middleware('perm:units.edit')->name('units.contract_template.update');
        Route::post('units/contract-template/reset', [HallContractTemplateController::class, 'reset'])->middleware('perm:units.edit')->name('units.contract_template.reset');

        // الوحدات والأقسام
        // شاشة واحدة بثلاث مداخل: القاعات، الشاليهات، والكل — يفصلها المقطع {type}
        Route::get('units/{type?}', [UnitsController::class, 'index'])->where('type', 'halls|chalets')->middleware('perm:units.view')->name('units.index');
        Route::post('units', [UnitsController::class, 'store'])->middleware('perm:units.create')->name('units.store');
        Route::put('units/{unit}', [UnitsController::class, 'update'])->middleware('perm:units.edit')->name('units.update');
        Route::patch('units/{unit}/toggle', [UnitsController::class, 'toggle'])->middleware('perm:units.edit')->name('units.toggle');
        // رفع الشعار وحده — تستدعيه مساحة العمل دون فتح نموذج الوحدة كاملًا
        Route::post('units/{unit}/logo', [UnitsController::class, 'updateLogo'])->middleware('perm:units.edit')->name('units.logo.update');
        Route::delete('units/{unit}', [UnitsController::class, 'destroy'])->middleware('perm:units.delete')->name('units.destroy');

        // تسعيرة الوحدات — تُدار من نافذة الأسعار داخل شاشة الوحدات
        Route::put('units/{unit}/prices', [PricingController::class, 'updatePrices'])->middleware('perm:units.edit')->name('units.prices.update');

        // مساحة عمل وحدة واحدة — حجوزاتها وفواتيرها وربحيتها
        Route::get('units/{unit}/workspace', [UnitWorkspaceController::class, 'show'])
            ->middleware('perm:units.view')->name('units.workspace');

        // باقات القاعات
        Route::get('packages', [PackagesController::class, 'index'])->middleware('perm:packages.view')->name('packages.index');
        Route::post('packages', [PackagesController::class, 'store'])->middleware('perm:packages.create')->name('packages.store');
        Route::put('packages/{package}', [PackagesController::class, 'update'])->middleware('perm:packages.edit')->name('packages.update');
        Route::patch('packages/{package}/toggle', [PackagesController::class, 'toggle'])->middleware('perm:packages.edit')->name('packages.toggle');
        Route::post('packages/{package}/duplicate', [PackagesController::class, 'duplicate'])->middleware('perm:packages.create')->name('packages.duplicate');
        Route::delete('packages/{package}', [PackagesController::class, 'destroy'])->middleware('perm:packages.delete')->name('packages.destroy');

        // أنواع المناسبات — تظهر في نموذج الحجز
        Route::get('event-types', [EventTypesController::class, 'index'])->middleware('perm:event_types.view')->name('event_types.index');
        Route::post('event-types', [EventTypesController::class, 'store'])->middleware('perm:event_types.create')->name('event_types.store');
        Route::put('event-types/{eventType}', [EventTypesController::class, 'update'])->middleware('perm:event_types.edit')->name('event_types.update');
        Route::patch('event-types/{eventType}/toggle', [EventTypesController::class, 'toggle'])->middleware('perm:event_types.edit')->name('event_types.toggle');
        Route::delete('event-types/{eventType}', [EventTypesController::class, 'destroy'])->middleware('perm:event_types.delete')->name('event_types.destroy');

        // مرافق الوحدات
        Route::get('units-facilities', [FacilitiesController::class, 'index'])->middleware('perm:units.view')->name('facilities.index');
        Route::post('units-facilities', [FacilitiesController::class, 'store'])->middleware('perm:units.create')->name('facilities.store');
        Route::put('units-facilities/{facility}', [FacilitiesController::class, 'update'])->middleware('perm:units.edit')->name('facilities.update');
        Route::delete('units-facilities/{facility}', [FacilitiesController::class, 'destroy'])->middleware('perm:units.delete')->name('facilities.destroy');

        /*
        | حجوزات القاعات وتقويمها — مناسبة داخل يوم واحد بفترة محددة.
        | المسارات النوعية تسبق مسارات {booking} حتى لا يُفسَّر «halls» كمعرّف.
        */
        // «month» قبل شيء آخر تحت calendar/halls — وهي كلمة لا معرّف فلا تلتبس
        Route::get('calendar/halls/month', [HallMonthCalendarController::class, 'index'])->middleware('perm:calendar.view')->name('calendar.halls.month');
        Route::get('calendar/halls', [HallCalendarController::class, 'index'])->middleware('perm:calendar.view')->name('calendar.halls');
        Route::get('bookings/halls', [HallBookingsController::class, 'index'])->middleware('perm:bookings.view')->name('bookings.halls.index');
        // create قبل {booking} حتى لا تُفسَّر كلمةً معرّفًا
        Route::get('bookings/halls/create', [HallBookingsController::class, 'create'])->middleware('perm:bookings.create')->name('bookings.halls.create');
        Route::get('bookings/halls/{booking}/edit', [HallBookingsController::class, 'edit'])->middleware('perm:bookings.edit')->name('bookings.halls.edit');
        Route::post('bookings/halls/quote', [HallBookingsController::class, 'quote'])->middleware('perm:bookings.view')->name('bookings.halls.quote');
        Route::post('bookings/halls', [HallBookingsController::class, 'store'])->middleware('perm:bookings.create')->name('bookings.halls.store');
        Route::put('bookings/halls/{booking}', [HallBookingsController::class, 'update'])->middleware('perm:bookings.edit')->name('bookings.halls.update');

        /*
        | حجوزات الشاليهات وتقويمها — إقامة ممتدة بتاريخ دخول وخروج وعدد ليالٍ.
        */
        Route::get('calendar/chalets', [ChaletCalendarController::class, 'index'])->middleware('perm:calendar.view')->name('calendar.chalets');
        Route::get('bookings/chalets', [ChaletBookingsController::class, 'index'])->middleware('perm:bookings.view')->name('bookings.chalets.index');
        Route::get('bookings/chalets/create', [ChaletBookingsController::class, 'create'])->middleware('perm:bookings.create')->name('bookings.chalets.create');
        Route::get('bookings/chalets/{booking}/edit', [ChaletBookingsController::class, 'edit'])->middleware('perm:bookings.edit')->name('bookings.chalets.edit');
        Route::post('bookings/chalets/quote', [ChaletBookingsController::class, 'quote'])->middleware('perm:bookings.view')->name('bookings.chalets.quote');
        Route::post('bookings/chalets', [ChaletBookingsController::class, 'store'])->middleware('perm:bookings.create')->name('bookings.chalets.store');
        Route::put('bookings/chalets/{booking}', [ChaletBookingsController::class, 'update'])->middleware('perm:bookings.edit')->name('bookings.chalets.update');

        /*
        | ما بعد الحجز مشترك بين النوعين: الحالة والدفعات والتذكير والحذف.
        */
        Route::patch('bookings/{booking}/status', [BookingsController::class, 'changeStatus'])->middleware('perm:bookings.edit')->name('bookings.status');
        Route::get('bookings/{booking}/payments', [BookingsController::class, 'payments'])->middleware('perm:bookings.view')->name('bookings.payments');
        Route::post('bookings/{booking}/payments', [BookingsController::class, 'storePayment'])->middleware('perm:bookings.edit')->name('bookings.payments.store');
        Route::post('bookings/{booking}/remind', [BookingsController::class, 'remind'])->middleware('perm:whatsapp.send')->name('bookings.remind');
        Route::patch('bookings/{booking}/notes', [BookingsController::class, 'updateNotes'])->middleware('perm:bookings.edit')->name('bookings.notes');
        Route::get('bookings/{booking}/bond', [BookingsController::class, 'bond'])->middleware('perm:bookings.view')->name('bookings.bond');
        Route::get('bookings/{booking}/invoice', [BookingsController::class, 'invoice'])->middleware('perm:bookings.view')->name('bookings.invoice');
        Route::delete('bookings/{booking}', [BookingsController::class, 'destroy'])->middleware('perm:bookings.delete')->name('bookings.destroy');

        // الروابط القديمة تُحوَّل إلى شاشة القاعات — الروابط المحفوظة لا تنكسر.
        Route::redirect('bookings', 'admin/bookings/halls')->name('bookings.index');
        Route::redirect('calendar', 'admin/calendar/halls')->name('calendar.index');
    });

    /*
    |--------------------------------------------------------------------------
    | نظام العقود والواتساب
    |--------------------------------------------------------------------------
    */
    Route::middleware('system:contracts')->group(function () {
        Route::get('contracts', [ContractsController::class, 'index'])->middleware('perm:contracts.view')->name('contracts.index');
        Route::get('contracts/{contract}', [ContractsController::class, 'show'])->middleware('perm:contracts.view')->name('contracts.show');
        Route::post('contracts', [ContractsController::class, 'store'])->middleware('perm:contracts.create')->name('contracts.store');
        Route::post('contracts/{contract}/refresh', [ContractsController::class, 'refresh'])->middleware('perm:contracts.edit')->name('contracts.refresh');
        Route::post('contracts/{contract}/send', [ContractsController::class, 'send'])->middleware('perm:contracts.send')->name('contracts.send');
        Route::patch('contracts/{contract}/status', [ContractsController::class, 'changeStatus'])->middleware('perm:contracts.edit')->name('contracts.status');
        Route::delete('contracts/{contract}', [ContractsController::class, 'destroy'])->middleware('perm:contracts.delete')->name('contracts.destroy');

        Route::get('contract-templates', [ContractTemplatesController::class, 'index'])->middleware('perm:contract_templates.view')->name('contract_templates.index');
        Route::post('contract-templates', [ContractTemplatesController::class, 'store'])->middleware('perm:contract_templates.create')->name('contract_templates.store');
        Route::put('contract-templates/{template}', [ContractTemplatesController::class, 'update'])->middleware('perm:contract_templates.edit')->name('contract_templates.update');
        Route::delete('contract-templates/{template}', [ContractTemplatesController::class, 'destroy'])->middleware('perm:contract_templates.delete')->name('contract_templates.destroy');

        Route::get('whatsapp-log', [WhatsappLogController::class, 'index'])->middleware('perm:whatsapp.view')->name('whatsapp.log');
    });

    /*
    |--------------------------------------------------------------------------
    | نظام نقطة البيع والمخزون
    |--------------------------------------------------------------------------
    */
    Route::middleware('system:pos')->group(function () {
        Route::get('pos', [PosController::class, 'index'])->middleware('perm:pos.view')->name('pos.index');
        Route::post('pos/checkout', [PosController::class, 'checkout'])->middleware('perm:pos.create')->name('pos.checkout');
        Route::post('pos/sales/{sale}/refund', [PosController::class, 'refund'])->middleware('perm:sales.create')->name('pos.refund');

        // سجل الفواتير — استعراض فواتير القسم وحده مع مرتجعاتها وسنداتها
        Route::get('sales', [SalesController::class, 'index'])->middleware('perm:sales.view')->name('sales.index');
        Route::get('sales/{sale}', [SalesController::class, 'show'])->middleware('perm:sales.view')->name('sales.show');
        Route::post('sales/{sale}/settle', [SalesController::class, 'settle'])->middleware('perm:sales.create')->name('sales.settle');
        Route::post('sales/{sale}/refund', [SalesController::class, 'refund'])->middleware('perm:sales.create')->name('sales.refund');

        Route::get('items', [ItemsController::class, 'index'])->middleware('perm:items.view')->name('items.index');
        Route::post('items', [ItemsController::class, 'store'])->middleware('perm:items.create')->name('items.store');
        Route::put('items/{item}', [ItemsController::class, 'update'])->middleware('perm:items.edit')->name('items.update');
        Route::patch('items/{item}/toggle', [ItemsController::class, 'toggle'])->middleware('perm:items.edit')->name('items.toggle');
        Route::delete('items/{item}', [ItemsController::class, 'destroy'])->middleware('perm:items.delete')->name('items.destroy');

        Route::post('inventory/adjust', [ItemsController::class, 'adjust'])->middleware('perm:inventory.approve')->name('inventory.adjust');
        Route::get('inventory/movements', [ItemsController::class, 'movements'])->middleware('perm:inventory.view')->name('inventory.movements');

        // وحدات القياس وأقسام المستودع
        Route::get('inventory/units', [MeasureUnitsController::class, 'index'])->middleware('perm:items.view')->name('measure_units.index');
        Route::post('inventory/units', [MeasureUnitsController::class, 'store'])->middleware('perm:items.create')->name('measure_units.store');
        Route::put('inventory/units/{unit}', [MeasureUnitsController::class, 'update'])->middleware('perm:items.edit')->name('measure_units.update');
        Route::delete('inventory/units/{unit}', [MeasureUnitsController::class, 'destroy'])->middleware('perm:items.delete')->name('measure_units.destroy');

        Route::post('inventory/departments', [MeasureUnitsController::class, 'storeDepartment'])->middleware('perm:items.create')->name('inv_departments.store');
        Route::put('inventory/departments/{department}', [MeasureUnitsController::class, 'updateDepartment'])->middleware('perm:items.edit')->name('inv_departments.update');
        Route::delete('inventory/departments/{department}', [MeasureUnitsController::class, 'destroyDepartment'])->middleware('perm:items.delete')->name('inv_departments.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | نظام المحاسبة
    |--------------------------------------------------------------------------
    */
    Route::middleware('system:accounting')->group(function () {
        Route::get('accounting/accounts', [AccountingController::class, 'accounts'])->middleware('perm:accounts.view')->name('accounts.index');
        Route::post('accounting/accounts', [AccountingController::class, 'storeAccount'])->middleware('perm:accounts.create')->name('accounts.store');
        Route::put('accounting/accounts/{account}', [AccountingController::class, 'updateAccount'])->middleware('perm:accounts.edit')->name('accounts.update');
        Route::delete('accounting/accounts/{account}', [AccountingController::class, 'destroyAccount'])->middleware('perm:accounts.delete')->name('accounts.destroy');

        Route::get('accounting/journal', [AccountingController::class, 'journal'])->middleware('perm:journal.view')->name('journal.index');
        Route::post('accounting/journal', [AccountingController::class, 'storeEntry'])->middleware('perm:journal.create')->name('journal.store');
        Route::post('accounting/journal/{entry}/reverse', [AccountingController::class, 'reverseEntry'])->middleware('perm:journal.approve')->name('journal.reverse');

        Route::get('accounting/vouchers', [AccountingController::class, 'vouchers'])->middleware('perm:vouchers.view')->name('vouchers.index');
        Route::post('accounting/vouchers', [AccountingController::class, 'storeVoucher'])->middleware('perm:vouchers.create')->name('vouchers.store');
        Route::post('accounting/vouchers/{voucher}/post', [AccountingController::class, 'postVoucher'])->middleware('perm:vouchers.approve')->name('vouchers.post');
        Route::post('accounting/vouchers/{voucher}/cancel', [AccountingController::class, 'cancelVoucher'])->middleware('perm:vouchers.approve')->name('vouchers.cancel');

        Route::get('accounting/reports', [FinancialReportsController::class, 'index'])->middleware('perm:fin_reports.view')->name('fin_reports.index');
    });

    /*
    |--------------------------------------------------------------------------
    | نظام الموارد البشرية والرواتب
    |--------------------------------------------------------------------------
    */
    Route::middleware('system:hr')->group(function () {
        Route::get('hr/staff', [HrController::class, 'staff'])->middleware('perm:staff.view')->name('staff.index');
        Route::post('hr/staff', [HrController::class, 'storeStaff'])->middleware('perm:staff.create')->name('staff.store');
        Route::put('hr/staff/{employee}', [HrController::class, 'updateStaff'])->middleware('perm:staff.edit')->name('staff.update');
        Route::delete('hr/staff/{employee}', [HrController::class, 'destroyStaff'])->middleware('perm:staff.delete')->name('staff.destroy');

        Route::get('hr/attendance', [HrController::class, 'attendance'])->middleware('perm:attendance.view')->name('attendance.index');
        Route::post('hr/attendance', [HrController::class, 'saveAttendance'])->middleware('perm:attendance.edit')->name('attendance.save');

        Route::get('hr/leaves', [HrController::class, 'leaves'])->middleware('perm:leaves.view')->name('leaves.index');
        Route::post('hr/leaves', [HrController::class, 'storeLeave'])->middleware('perm:leaves.create')->name('leaves.store');
        Route::patch('hr/leaves/{leave}/decide', [HrController::class, 'decideLeave'])->middleware('perm:leaves.approve')->name('leaves.decide');

        Route::post('hr/advances', [HrController::class, 'storeAdvance'])->middleware('perm:advances.create')->name('advances.store');
        Route::patch('hr/advances/{advance}/approve', [HrController::class, 'approveAdvance'])->middleware('perm:advances.approve')->name('advances.approve');

        Route::get('hr/payroll', [HrController::class, 'payrolls'])->middleware('perm:payroll.view')->name('payroll.index');
        Route::post('hr/payroll/generate', [HrController::class, 'generatePayroll'])->middleware('perm:payroll.create')->name('payroll.generate');
        Route::post('hr/payroll/{payroll}/approve', [HrController::class, 'approvePayroll'])->middleware('perm:payroll.approve')->name('payroll.approve');
    });

    // الموردون
    Route::get('suppliers', [SuppliersController::class, 'index'])->middleware('perm:suppliers.view')->name('suppliers.index');
    Route::post('suppliers', [SuppliersController::class, 'store'])->middleware('perm:suppliers.create')->name('suppliers.store');
    Route::put('suppliers/{supplier}', [SuppliersController::class, 'update'])->middleware('perm:suppliers.edit')->name('suppliers.update');
    Route::patch('suppliers/{supplier}/toggle', [SuppliersController::class, 'toggle'])->middleware('perm:suppliers.edit')->name('suppliers.toggle');
    Route::delete('suppliers/{supplier}', [SuppliersController::class, 'destroy'])->middleware('perm:suppliers.delete')->name('suppliers.destroy');

    // الموظفون
    Route::get('employees', [UsersController::class, 'index'])->middleware('perm:employees.view')->name('employees.index');
    Route::post('employees', [UsersController::class, 'store'])->middleware('perm:employees.create')->name('employees.store');

    // حسابات التجربة (إنشاء/حذف حسابات وهمية للعرض) — قبل مسارات {user} حتى لا تُفسَّر "demo" كمعرّف
    Route::post('employees/demo', [DemoAccountsController::class, 'store'])->middleware('perm:employees.create')->name('employees.demo.store');
    Route::delete('employees/demo', [DemoAccountsController::class, 'destroy'])->middleware('perm:employees.delete')->name('employees.demo.destroy');

    Route::put('employees/{user}', [UsersController::class, 'update'])->middleware('perm:employees.edit')->name('employees.update');
    Route::patch('employees/{user}/toggle', [UsersController::class, 'toggle'])->middleware('perm:employees.edit')->name('employees.toggle');
    Route::patch('employees/{user}/scope', [UsersController::class, 'toggleScope'])->middleware('perm:employees.edit')->name('employees.scope');
    Route::delete('employees/{user}', [UsersController::class, 'destroy'])->middleware('perm:employees.delete')->name('employees.destroy');

    // الأدوار والصلاحيات
    Route::get('roles', [RolesController::class, 'index'])->middleware('perm:roles.view')->name('roles.index');
    Route::post('roles', [RolesController::class, 'store'])->middleware('perm:roles.create')->name('roles.store');
    Route::put('roles/{role}', [RolesController::class, 'update'])->middleware('perm:roles.edit')->name('roles.update');
    Route::delete('roles/{role}', [RolesController::class, 'destroy'])->middleware('perm:roles.delete')->name('roles.destroy');

    // العملاء
    Route::get('clients', [ClientsController::class, 'index'])->middleware('perm:clients.view')->name('clients.index');
    Route::get('clients/export', [ClientsController::class, 'export'])->middleware('perm:clients.view')->name('clients.export');
    Route::post('clients', [ClientsController::class, 'store'])->middleware('perm:clients.create')->name('clients.store');
    // إضافة سريعة من شاشات الحجز — قبل مسارات {client} كي لا تُفسَّر "quick" معرّفًا
    Route::post('clients/quick', [ClientsController::class, 'quickStore'])->middleware('perm:clients.create')->name('clients.quick');
    Route::put('clients/{client}', [ClientsController::class, 'update'])->middleware('perm:clients.edit')->name('clients.update');
    Route::patch('clients/{client}/toggle', [ClientsController::class, 'toggle'])->middleware('perm:clients.edit')->name('clients.toggle');
    Route::delete('clients/{client}', [ClientsController::class, 'destroy'])->middleware('perm:clients.delete')->name('clients.destroy');

    // المدن
    Route::get('cities', [CitiesController::class, 'index'])->middleware('perm:cities.view')->name('cities.index');
    Route::post('cities', [CitiesController::class, 'store'])->middleware('perm:cities.create')->name('cities.store');
    Route::put('cities/{city}', [CitiesController::class, 'update'])->middleware('perm:cities.edit')->name('cities.update');
    Route::patch('cities/{city}/toggle', [CitiesController::class, 'toggle'])->middleware('perm:cities.edit')->name('cities.toggle');
    Route::delete('cities/{city}', [CitiesController::class, 'destroy'])->middleware('perm:cities.delete')->name('cities.destroy');

    // الأقسام
    Route::get('departments', [DepartmentsController::class, 'index'])->middleware('perm:departments.view')->name('departments.index');
    Route::post('departments', [DepartmentsController::class, 'store'])->middleware('perm:departments.create')->name('departments.store');
    Route::put('departments/{department}', [DepartmentsController::class, 'update'])->middleware('perm:departments.edit')->name('departments.update');
    Route::delete('departments/{department}', [DepartmentsController::class, 'destroy'])->middleware('perm:departments.delete')->name('departments.destroy');

    // تذاكر الدعم الفني
    Route::get('tickets', [TicketsController::class, 'index'])->middleware('perm:tickets.view')->name('tickets.index');
    Route::post('tickets', [TicketsController::class, 'store'])->middleware('perm:tickets.create')->name('tickets.store');
    Route::patch('tickets/{ticket}/close', [TicketsController::class, 'close'])->middleware('perm:tickets.edit')->name('tickets.close');
    Route::patch('tickets/{ticket}/reopen', [TicketsController::class, 'reopen'])->middleware('perm:tickets.edit')->name('tickets.reopen');
    Route::delete('tickets/{ticket}', [TicketsController::class, 'destroy'])->middleware('perm:tickets.delete')->name('tickets.destroy');

    // الإعدادات العامة
    Route::get('settings/general', [GeneralSettingsController::class, 'edit'])->middleware('perm:settings.view')->name('settings.general.edit');
    Route::post('settings/general', [GeneralSettingsController::class, 'update'])->middleware('perm:settings.edit')->name('settings.general.update');

    // إعدادات الواتساب (بوابة c-wts.com)
    Route::get('settings/whatsapp', [WhatsappSettingsController::class, 'edit'])->middleware('perm:settings.view')->name('settings.whatsapp.edit');
    Route::post('settings/whatsapp', [WhatsappSettingsController::class, 'update'])->middleware('perm:settings.edit')->name('settings.whatsapp.update');
    Route::get('settings/whatsapp/status', [WhatsappSettingsController::class, 'status'])->middleware('perm:settings.view')->name('settings.whatsapp.status');
    Route::post('settings/whatsapp/test', [WhatsappSettingsController::class, 'test'])->middleware('perm:settings.edit')->name('settings.whatsapp.test');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
