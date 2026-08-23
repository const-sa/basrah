<?php

namespace App\Providers;

use App\Http\Controllers\Admin\RolesController;
use App\Models\AuditLog;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\BookingTimes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Booking hours are read many times per request (every period label in
        // a list), so they resolve once. A singleton rather than a static so
        // nothing leaks between tests, and Setting::booted() drops it on save.
        $this->app->singleton(BookingTimes::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // المدير العام يتجاوز كل الفحوصات.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // تسجيل بوابة (Gate) لكل صلاحية: يمكن استخدام can('clients.create') أو @can في الواجهة.
        foreach (RolesController::permissionKeys() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        // السجل الرقابي: مراقبٌ واحد على كل نوع حسّاس. التسجيل هنا لا داخل
        // النماذج، حتى تبقى قائمة ما يُراقَب مقروءةً في موضع واحد.
        foreach (array_keys(AuditLog::SUBJECTS) as $model) {
            $model::observe(AuditObserver::class);
        }

        $this->explainArchivedUniqueClashes();
    }

    /**
     * «القيمة مستعملة» حين يكون المستعمِل سجلًا مؤرشفًا.
     *
     * الأرشفة تُبقي الصف في الجدول، والفهرس الفريد لا يفرّق بين حيٍّ ومؤرشف.
     * فمن حذف مدينة «البصرة» ثم أعادها يُقال له «الاسم مستخدم» وهو لا يرى في
     * الشاشة مدينةً بهذا الاسم — طريقٌ مسدود لا يدلّ على مخرجه.
     *
     * فتُبدَّل الرسالة وحدها حين يكون المزاحم مؤرشفًا لا حيًّا، لتدلّ على
     * الأرشيف: يُسترجع السجل أو يُتلف نهائيًا، ثم يُعاد الإدخال.
     */
    private function explainArchivedUniqueClashes(): void
    {
        Validator::replacer('unique', function (string $message, string $attribute, string $rule, array $parameters, $validator) {
            $table = $parameters[0] ?? null;

            if (! $table) {
                return $message;
            }

            // القاعدة تقبل اسم جدول أو اسم نموذج.
            if (class_exists($table)) {
                $table = (new $table)->getTable();
            }

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                return $message;
            }

            $column = ($parameters[1] ?? 'NULL') !== 'NULL' ? $parameters[1] : $attribute;
            $value = data_get($validator->getData(), $attribute);

            $rows = DB::table($table)->where($column, $value);

            // المزاحم الحيّ يُبقي الرسالة الأصلية: هو ظاهر في الشاشة ويُرى.
            if ((clone $rows)->whereNull('deleted_at')->exists()) {
                return $message;
            }

            return (clone $rows)->whereNotNull('deleted_at')->exists()
                ? 'القيمة مستعملة في سجل مؤرشف — استرجعه من الأرشيف أو احذفه نهائيًا ثم أعد المحاولة.'
                : $message;
        });
    }
}
