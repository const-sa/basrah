<?php

use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\ArchiveController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupsController;
use App\Http\Controllers\Admin\BookingsController;
use App\Http\Controllers\Admin\ChaletBookingsController;
use App\Http\Controllers\Admin\ChaletCalendarController;
use App\Http\Controllers\Admin\CitiesController;
use App\Http\Controllers\Admin\ClientsController;
use App\Http\Controllers\Admin\ContractsController;
use App\Http\Controllers\Admin\ContractTemplatesController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DemoAccountsController;
use App\Http\Controllers\Admin\DepartmentsController;
use App\Http\Controllers\Admin\EventTypesController;
use App\Http\Controllers\Admin\ExpensesController;
use App\Http\Controllers\Admin\FacilitiesController;
use App\Http\Controllers\Admin\FinancialReportsController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Admin\HallBookingsController;
use App\Http\Controllers\Admin\HallCalendarController;
use App\Http\Controllers\Admin\HallContractTemplateController;
use App\Http\Controllers\Admin\HallMonthCalendarController;
use App\Http\Controllers\Admin\HrController;
use App\Http\Controllers\Admin\ItemsController;
use App\Http\Controllers\Admin\MeasureUnitsController;
use App\Http\Controllers\Admin\NotificationsController;
use App\Http\Controllers\Admin\NotificationTemplatesController;
use App\Http\Controllers\Admin\PackagesController;
use App\Http\Controllers\Admin\PaymentMethodsController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\PricingController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\RevenuesController;
use App\Http\Controllers\Admin\RolesController;
use App\Http\Controllers\Admin\SalesController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\QuotationController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SuppliersController;
use App\Http\Controllers\Admin\TicketsController;
use App\Http\Controllers\Admin\UnitsController;
use App\Http\Controllers\Admin\UnitWorkspaceController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\WhatsappLogController;
use App\Http\Controllers\Admin\WhatsappSettingsController;
use App\Http\Controllers\Site\OnlineBookingController;
use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| الواجهة العامة والحجز أونلاين (§12)
|--------------------------------------------------------------------------
| مسارات بلا مصادقة يصلها الزائر. مقيَّدة بمعدّل طلبات لأنها الوجه المكشوف
| للنظام: نموذج حجزٍ مفتوح بلا حدّ يُملأ آليًا فيقفل التقويم بحجوزات وهمية.
*/
Route::get('/', [SiteController::class, 'home'])->name('home');
Route::get('units/{unit}', [SiteController::class, 'unit'])->name('site.unit');

Route::get('book/{unit}', [OnlineBookingController::class, 'create'])->name('site.book');
Route::post('book/{unit}/quote', [OnlineBookingController::class, 'quote'])
    ->middleware('throttle:30,1')->name('site.book.quote');
Route::post('book/{unit}', [OnlineBookingController::class, 'store'])
    ->middleware('throttle:8,1')->name('site.book.store');
Route::get('booking/{reference}', [OnlineBookingController::class, 'show'])->name('site.booking.show');

Route::prefix('admin')->middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->middleware('perm:dashboard.view')->name('dashboard');

    Route::get('api/search', [SearchController::class, 'index'])->name('api.search');

    // مركز التقارير — تقارير الحجوزات والمالية والموظفين (§12)
    Route::get('reports', [ReportsController::class, 'index'])->middleware('perm:reports.view')->name('reports.index');
    // التصدير قبل مسار العرض كي لا تُفسَّر «export» مفتاحَ تقرير
    Route::get('reports/{report}/export', [ReportsController::class, 'export'])->middleware('perm:reports.export')->name('reports.export');
    Route::get('reports/{report}', [ReportsController::class, 'show'])->middleware('perm:reports.view')->name('reports.show');

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
    | الوسيط system:halls|chalets يحرس النشاطين، ثم تفصل صلاحية الإجراء داخلهما.
    */
    Route::middleware('system:halls|chalets')->group(function () {
        // نموذج العقد المعتمد للقاعات — قبل units/{type?} حتى لا يُلتقط كمقطع نوع
        Route::get('units/contract-template', [HallContractTemplateController::class, 'show'])->middleware('perm:hall_contract.view')->name('units.contract_template');
        Route::put('units/contract-template', [HallContractTemplateController::class, 'update'])->middleware('perm:hall_contract.edit')->name('units.contract_template.update');
        Route::post('units/contract-template/reset', [HallContractTemplateController::class, 'reset'])->middleware('perm:hall_contract.edit')->name('units.contract_template.reset');

        // الوحدات والأقسام
        // شاشة واحدة بثلاث مداخل: القاعات، الشاليهات، والكل — يفصلها المقطع {type}
        Route::get('units/{type?}', [UnitsController::class, 'index'])->where('type', 'halls|chalets')->middleware('perm:halls.view|chalets.view')->name('units.index');
        Route::post('units', [UnitsController::class, 'store'])->middleware('perm:halls.create|chalets.create')->name('units.store');
        Route::put('units/{unit}', [UnitsController::class, 'update'])->middleware('perm:halls.edit|chalets.edit')->name('units.update');
        Route::patch('units/{unit}/toggle', [UnitsController::class, 'toggle'])->middleware('perm:halls.edit|chalets.edit')->name('units.toggle');
        // رفع الشعار وحده — تستدعيه مساحة العمل دون فتح نموذج الوحدة كاملًا
        Route::post('units/{unit}/logo', [UnitsController::class, 'updateLogo'])->middleware('perm:halls.edit|chalets.edit')->name('units.logo.update');
        Route::delete('units/{unit}', [UnitsController::class, 'destroy'])->middleware('perm:halls.delete|chalets.delete')->name('units.destroy');

        // تسعيرة الوحدات — تُدار من نافذة الأسعار داخل شاشة الوحدات
        Route::put('units/{unit}/prices', [PricingController::class, 'updatePrices'])->middleware('perm:halls.edit|chalets.edit')->name('units.prices.update');

        // مساحة عمل وحدة واحدة — حجوزاتها وفواتيرها وربحيتها
        Route::get('units/{unit}/workspace', [UnitWorkspaceController::class, 'show'])
            ->middleware('perm:halls.view|chalets.view')->name('units.workspace');

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
        Route::get('units-facilities', [FacilitiesController::class, 'index'])->middleware('perm:halls.view|chalets.view')->name('facilities.index');
        Route::post('units-facilities', [FacilitiesController::class, 'store'])->middleware('perm:halls.create|chalets.create')->name('facilities.store');
        Route::put('units-facilities/{facility}', [FacilitiesController::class, 'update'])->middleware('perm:halls.edit|chalets.edit')->name('facilities.update');
        Route::delete('units-facilities/{facility}', [FacilitiesController::class, 'destroy'])->middleware('perm:halls.delete|chalets.delete')->name('facilities.destroy');

        /*
        | حجوزات القاعات وتقويمها — مناسبة داخل يوم واحد بفترة محددة.
        | المسارات النوعية تسبق مسارات {booking} حتى لا يُفسَّر «halls» كمعرّف.
        */
        // «month» قبل شيء آخر تحت calendar/halls — وهي كلمة لا معرّف فلا تلتبس
        Route::get('calendar/halls/month', [HallMonthCalendarController::class, 'index'])->middleware('perm:hall_calendar.view')->name('calendar.halls.month');
        Route::get('calendar/halls', [HallCalendarController::class, 'index'])->middleware('perm:hall_calendar.view')->name('calendar.halls');
        Route::get('bookings/halls', [HallBookingsController::class, 'index'])->middleware('perm:hall_bookings.view')->name('bookings.halls.index');
        // create قبل {booking} حتى لا تُفسَّر كلمةً معرّفًا
        Route::get('bookings/halls/create', [HallBookingsController::class, 'create'])->middleware('perm:hall_bookings.create')->name('bookings.halls.create');
        Route::get('bookings/halls/{booking}/edit', [HallBookingsController::class, 'edit'])->middleware('perm:hall_bookings.edit')->name('bookings.halls.edit');
        Route::post('bookings/halls/quote', [HallBookingsController::class, 'quote'])->middleware('perm:hall_bookings.view')->name('bookings.halls.quote');
        Route::post('bookings/halls', [HallBookingsController::class, 'store'])->middleware('perm:hall_bookings.create')->name('bookings.halls.store');
        Route::put('bookings/halls/{booking}', [HallBookingsController::class, 'update'])->middleware('perm:hall_bookings.edit')->name('bookings.halls.update');

        /*
        | حجوزات الشاليهات وتقويمها — إقامة ممتدة بتاريخ دخول وخروج وعدد ليالٍ.
        */
        Route::get('calendar/chalets', [ChaletCalendarController::class, 'index'])->middleware('perm:chalet_calendar.view')->name('calendar.chalets');
        Route::get('bookings/chalets', [ChaletBookingsController::class, 'index'])->middleware('perm:chalet_bookings.view')->name('bookings.chalets.index');
        Route::get('bookings/chalets/create', [ChaletBookingsController::class, 'create'])->middleware('perm:chalet_bookings.create')->name('bookings.chalets.create');
        Route::get('bookings/chalets/{booking}/edit', [ChaletBookingsController::class, 'edit'])->middleware('perm:chalet_bookings.edit')->name('bookings.chalets.edit');
        Route::post('bookings/chalets/quote', [ChaletBookingsController::class, 'quote'])->middleware('perm:chalet_bookings.view')->name('bookings.chalets.quote');
        Route::post('bookings/chalets', [ChaletBookingsController::class, 'store'])->middleware('perm:chalet_bookings.create')->name('bookings.chalets.store');
        Route::put('bookings/chalets/{booking}', [ChaletBookingsController::class, 'update'])->middleware('perm:chalet_bookings.edit')->name('bookings.chalets.update');

        /*
        | ما بعد الحجز مشترك بين النوعين: الحالة والدفعات والتذكير والحذف.
        */
        Route::patch('bookings/{booking}/status', [BookingsController::class, 'changeStatus'])->middleware('perm:hall_bookings.edit|chalet_bookings.edit')->name('bookings.status');
        Route::get('bookings/{booking}/payments', [BookingsController::class, 'payments'])->middleware('perm:hall_bookings.view|chalet_bookings.view')->name('bookings.payments');
        Route::post('bookings/{booking}/payments', [BookingsController::class, 'storePayment'])->middleware('perm:hall_bookings.edit|chalet_bookings.edit')->name('bookings.payments.store');
        Route::post('bookings/{booking}/remind', [BookingsController::class, 'remind'])->middleware('perm:whatsapp.send')->name('bookings.remind');
        // تذكير المتبقي وإرسال الفاتورة (§14)
        Route::post('bookings/{booking}/remind-balance', [BookingsController::class, 'remindBalance'])->middleware('perm:whatsapp.send')->name('bookings.remind.balance');
        Route::post('bookings/{booking}/invoice/send', [BookingsController::class, 'sendInvoice'])->middleware('perm:whatsapp.send')->name('bookings.invoice.send');
        Route::patch('bookings/{booking}/notes', [BookingsController::class, 'updateNotes'])->middleware('perm:hall_bookings.edit|chalet_bookings.edit')->name('bookings.notes');
        Route::get('bookings/{booking}/bond', [BookingsController::class, 'bond'])->middleware('perm:hall_bookings.view|chalet_bookings.view')->name('bookings.bond');
        Route::get('bookings/{booking}/invoice', [BookingsController::class, 'invoice'])->middleware('perm:hall_bookings.view|chalet_bookings.view')->name('bookings.invoice');
        Route::delete('bookings/{booking}', [BookingsController::class, 'destroy'])->middleware('perm:hall_bookings.delete|chalet_bookings.delete')->name('bookings.destroy');

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
        // The pools activity's own contract register — the same screen narrowed
        // to what was drawn from that activity's quotations. Its own path, not
        // a query string, so the menu keeps highlighting it while filtering.
        Route::get('pools/contracts', [ContractsController::class, 'poolsIndex'])->middleware('perm:contracts.view')->name('contracts.pools');
        // «pdf» قبل {contract} لا يلزم هنا لأنه مقطع ثانٍ، لكن ترتيبه قبل
        // show يبقي المسارات النوعية مجتمعة كما في بقية الملف.
        Route::get('contracts/{contract}/pdf', [ContractsController::class, 'pdf'])->middleware('perm:contracts.export')->name('contracts.pdf');
        Route::get('contracts/{contract}', [ContractsController::class, 'show'])->middleware('perm:contracts.view')->name('contracts.show');
        Route::post('contracts', [ContractsController::class, 'store'])->middleware('perm:contracts.create')->name('contracts.store');
        // Pools contracts are drawn from a quotation, not a booking — a separate
        // endpoint keeps each source's validation to its own fields.
        Route::post('contracts/from-quotation', [ContractsController::class, 'storeFromQuotation'])->middleware('perm:contracts.create')->name('contracts.from_quotation');
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
    Route::middleware('system:pools')->group(function () {
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
        // Quick add from the purchase invoice — before the {item} routes so "quick" is not read as an id
        Route::post('items/quick', [ItemsController::class, 'quickStore'])->middleware('perm:items.create')->name('items.quick');
        Route::put('items/{item}', [ItemsController::class, 'update'])->middleware('perm:items.edit')->name('items.update');
        Route::patch('items/{item}/toggle', [ItemsController::class, 'toggle'])->middleware('perm:items.edit')->name('items.toggle');
        Route::delete('items/{item}', [ItemsController::class, 'destroy'])->middleware('perm:items.delete')->name('items.destroy');

        Route::post('inventory/items/{item}/adjust', [ItemsController::class, 'adjustStock'])->middleware('perm:items.edit')->name('items.adjust');

        // فواتير المشتريات
        Route::get('purchases', [PurchaseController::class, 'index'])->middleware('perm:purchases.view')->name('purchases.index');
        Route::get('purchases/create', [PurchaseController::class, 'create'])->middleware('perm:purchases.create')->name('purchases.create');
        Route::post('purchases', [PurchaseController::class, 'store'])->middleware('perm:purchases.create')->name('purchases.store');
        Route::get('purchases/{purchase}/edit', [PurchaseController::class, 'edit'])->middleware('perm:purchases.edit')->name('purchases.edit');
        Route::put('purchases/{purchase}', [PurchaseController::class, 'update'])->middleware('perm:purchases.edit')->name('purchases.update');
        Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy'])->middleware('perm:purchases.delete')->name('purchases.destroy');
        Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('perm:purchases.view')->name('purchases.show');

        // عروض الأسعار
        Route::get('quotations', [QuotationController::class, 'index'])->middleware('perm:quotations.view')->name('quotations.index');
        Route::get('quotations/create', [QuotationController::class, 'create'])->middleware('perm:quotations.create')->name('quotations.create');
        Route::post('quotations', [QuotationController::class, 'store'])->middleware('perm:quotations.create')->name('quotations.store');
        Route::get('quotations/{quotation}/edit', [QuotationController::class, 'edit'])->middleware('perm:quotations.edit')->name('quotations.edit');
        Route::put('quotations/{quotation}', [QuotationController::class, 'update'])->middleware('perm:quotations.edit')->name('quotations.update');
        Route::delete('quotations/{quotation}', [QuotationController::class, 'destroy'])->middleware('perm:quotations.delete')->name('quotations.destroy');
        Route::get('quotations/{quotation}', [QuotationController::class, 'show'])->middleware('perm:quotations.view')->name('quotations.show');
        Route::get('quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->middleware('perm:quotations.view')->name('quotations.pdf');



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

        // الإيرادات — قراءة الدفاتر من جهة الدخل، موزّعة على القاعات
        // والشاليهات والمسابح
        Route::get('accounting/revenues', [RevenuesController::class, 'index'])->middleware('perm:revenues.view')->name('revenues.index');
        Route::get('accounting/revenues/export', [RevenuesController::class, 'export'])->middleware('perm:revenues.export')->name('revenues.export');

        // المصروفات والتكاليف (§9) — سندات صرف بوجهٍ تشغيلي
        Route::get('accounting/expenses', [ExpensesController::class, 'index'])->middleware('perm:expenses.view')->name('expenses.index');
        Route::get('accounting/expenses/export', [ExpensesController::class, 'export'])->middleware('perm:expenses.view')->name('expenses.export');
        Route::post('accounting/expenses', [ExpensesController::class, 'store'])->middleware('perm:expenses.create')->name('expenses.store');
        Route::put('accounting/expenses/{expense}', [ExpensesController::class, 'update'])->middleware('perm:expenses.edit')->name('expenses.update');
        Route::post('accounting/expenses/{expense}/post', [ExpensesController::class, 'post'])->middleware('perm:expenses.approve')->name('expenses.post');
        Route::post('accounting/expenses/{expense}/cancel', [ExpensesController::class, 'cancel'])->middleware('perm:expenses.approve')->name('expenses.cancel');
        Route::delete('accounting/expenses/{expense}', [ExpensesController::class, 'destroy'])->middleware('perm:expenses.delete')->name('expenses.destroy');

        // أنواع المصروف — تُدار من الشاشة نفسها لا من شجرة الحسابات
        Route::post('accounting/expense-categories', [ExpensesController::class, 'storeCategory'])->middleware('perm:expenses.create')->name('expense_categories.store');
        Route::put('accounting/expense-categories/{category}', [ExpensesController::class, 'updateCategory'])->middleware('perm:expenses.edit')->name('expense_categories.update');
        Route::patch('accounting/expense-categories/{category}/toggle', [ExpensesController::class, 'toggleCategory'])->middleware('perm:expenses.edit')->name('expense_categories.toggle');
        Route::delete('accounting/expense-categories/{category}', [ExpensesController::class, 'destroyCategory'])->middleware('perm:expenses.delete')->name('expense_categories.destroy');

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

        Route::post('hr/bonuses', [HrController::class, 'storeBonus'])->middleware('perm:bonuses.create')->name('bonuses.store');
        Route::patch('hr/bonuses/{bonus}/approve', [HrController::class, 'approveBonus'])->middleware('perm:bonuses.approve')->name('bonuses.approve');
        Route::delete('hr/bonuses/{bonus}', [HrController::class, 'destroyBonus'])->middleware('perm:bonuses.delete')->name('bonuses.destroy');

        Route::get('hr/payroll', [HrController::class, 'payrolls'])->middleware('perm:payroll.view')->name('payroll.index');
        Route::post('hr/payroll/generate', [HrController::class, 'generatePayroll'])->middleware('perm:payroll.create')->name('payroll.generate');
        Route::post('hr/payroll/{payroll}/approve', [HrController::class, 'approvePayroll'])->middleware('perm:payroll.approve')->name('payroll.approve');
    });

    // الموردون
    Route::get('suppliers', [SuppliersController::class, 'index'])->middleware('perm:suppliers.view')->name('suppliers.index');
    Route::post('suppliers', [SuppliersController::class, 'store'])->middleware('perm:suppliers.create')->name('suppliers.store');
    // Quick add from the purchase invoice — before the {supplier} routes so "quick" is not read as an id
    Route::post('suppliers/quick', [SuppliersController::class, 'quickStore'])->middleware('perm:suppliers.create')->name('suppliers.quick');
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

    // السجل الرقابي — للقراءة والتصدير فقط
    Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('perm:audit.view')->name('audit.index');
    Route::get('audit-log/export', [AuditLogController::class, 'export'])->middleware('perm:audit.export')->name('audit.export');

    // النسخ الاحتياطي (§18) — التنزيل بصلاحية العرض لأنه القاعدة كاملة
    Route::get('backups', [BackupsController::class, 'index'])->middleware('perm:backups.view')->name('backups.index');
    Route::get('backups/{backup}/download', [BackupsController::class, 'download'])->middleware('perm:backups.view')->name('backups.download');
    // تنزيل نسخة طازجة بنقرة واحدة — تُؤخذ ثم تُرسل في الرد نفسه.
    Route::get('backups/export', [BackupsController::class, 'export'])->middleware('perm:backups.create')->name('backups.export');
    Route::post('backups', [BackupsController::class, 'store'])->middleware('perm:backups.create')->name('backups.store');
    Route::post('backups/upload', [BackupsController::class, 'upload'])->middleware('perm:backups.create')->name('backups.upload');
    Route::post('backups/{backup}/restore', [BackupsController::class, 'restore'])->middleware('perm:backups.restore')->name('backups.restore');
    Route::delete('backups/{backup}', [BackupsController::class, 'destroy'])->middleware('perm:backups.delete')->name('backups.destroy');

    // الأرشيف — المحذوفات: استعراض واسترجاع، والإتلاف النهائي بصلاحية مستقلة
    Route::get('archive', [ArchiveController::class, 'index'])->middleware('perm:archive.view')->name('archive.index');
    Route::post('archive/{type}/{id}/restore', [ArchiveController::class, 'restore'])->middleware('perm:archive.restore')->name('archive.restore');
    Route::delete('archive/{type}/{id}', [ArchiveController::class, 'destroy'])->middleware('perm:archive.delete')->name('archive.destroy');

    // المجموعات وصلاحياتها — كانت تُسمّى «الأدوار»، والاسم الجديد هو الذي
    // تعرفه الإدارة. المسار القديم /admin/roles يُحوَّل حتى لا تنكسر الروابط
    // المحفوظة، وأسماء المسارات (roles.*) باقية لأن مفتاح الصلاحية roles.* .
    Route::get('groups', [RolesController::class, 'index'])->middleware('perm:roles.view')->name('roles.index');
    Route::post('groups', [RolesController::class, 'store'])->middleware('perm:roles.create')->name('roles.store');
    Route::put('groups/{role}', [RolesController::class, 'update'])->middleware('perm:roles.edit')->name('roles.update');
    Route::delete('groups/{role}', [RolesController::class, 'destroy'])->middleware('perm:roles.delete')->name('roles.destroy');
    Route::redirect('roles', 'admin/groups');

    // العملاء
    Route::get('clients', [ClientsController::class, 'index'])->middleware('perm:clients.view')->name('clients.index');
    Route::get('clients/export', [ClientsController::class, 'export'])->middleware('perm:clients.view')->name('clients.export');
    Route::post('clients', [ClientsController::class, 'store'])->middleware('perm:clients.create')->name('clients.store');
    // إضافة سريعة من شاشات الحجز — قبل مسارات {client} كي لا تُفسَّر "quick" معرّفًا
    Route::post('clients/quick', [ClientsController::class, 'quickStore'])->middleware('perm:clients.create')->name('clients.quick');
    // ملف العميل — بعد المسارات الثابتة كي لا تُفسَّر «export» معرّفًا
    Route::get('clients/{client}', [ClientsController::class, 'show'])->middleware('perm:clients.view')->name('clients.show');
    Route::put('clients/{client}', [ClientsController::class, 'update'])->middleware('perm:clients.edit')->name('clients.update');
    Route::patch('clients/{client}/toggle', [ClientsController::class, 'toggle'])->middleware('perm:clients.edit')->name('clients.toggle');
    Route::delete('clients/{client}', [ClientsController::class, 'destroy'])->middleware('perm:clients.delete')->name('clients.destroy');

    // طرق الدفع — تُدار من الإعدادات وتقرأها شاشات الحجوزات والكاشير والسندات
    Route::get('settings/payment-methods', [PaymentMethodsController::class, 'index'])->middleware('perm:payment_methods.view')->name('payment_methods.index');
    Route::post('settings/payment-methods', [PaymentMethodsController::class, 'store'])->middleware('perm:payment_methods.create')->name('payment_methods.store');
    Route::put('settings/payment-methods/{paymentMethod}', [PaymentMethodsController::class, 'update'])->middleware('perm:payment_methods.edit')->name('payment_methods.update');
    Route::patch('settings/payment-methods/{paymentMethod}/toggle', [PaymentMethodsController::class, 'toggle'])->middleware('perm:payment_methods.edit')->name('payment_methods.toggle');
    Route::delete('settings/payment-methods/{paymentMethod}', [PaymentMethodsController::class, 'destroy'])->middleware('perm:payment_methods.delete')->name('payment_methods.destroy');

    // المدن
    Route::get('cities', [CitiesController::class, 'index'])->middleware('perm:cities.view')->name('cities.index');
    Route::post('cities', [CitiesController::class, 'store'])->middleware('perm:cities.create')->name('cities.store');
    Route::put('cities/{city}', [CitiesController::class, 'update'])->middleware('perm:cities.edit')->name('cities.update');
    Route::patch('cities/{city}/toggle', [CitiesController::class, 'toggle'])->middleware('perm:cities.edit')->name('cities.toggle');
    Route::delete('cities/{city}', [CitiesController::class, 'destroy'])->middleware('perm:cities.delete')->name('cities.destroy');

    // الأقسام
    Route::get('departments', [DepartmentsController::class, 'index'])->middleware('perm:departments.view')->name('departments.index');
    // الأقسام ثابتة: لا يُسمح بالإضافة أو الحذف، التعديل فقط
    Route::put('departments/{department}', [DepartmentsController::class, 'update'])->middleware('perm:departments.edit')->name('departments.update');

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
    Route::get('settings/whatsapp/connect', [WhatsappSettingsController::class, 'connect'])->middleware('perm:settings.edit')->name('settings.whatsapp.connect');
    Route::post('settings/whatsapp/test', [WhatsappSettingsController::class, 'test'])->middleware('perm:settings.edit')->name('settings.whatsapp.test');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
