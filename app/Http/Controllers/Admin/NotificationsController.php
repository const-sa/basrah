<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class NotificationsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/notifications/Index', [
            'notifications' => self::demo(),
        ]);
    }

    /**
     * إشعارات تجريبية (بيانات وهمية للعرض).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function demo(): array
    {
        return [
            [
                'id' => 1,
                'type' => 'success',
                'title' => 'تم إضافة موظف جديد',
                'body' => 'قام المدير بإضافة الموظف «سالم العتيبي» إلى النظام.',
                'time' => 'قبل 5 دقائق',
                'read' => false,
            ],
            [
                'id' => 2,
                'type' => 'client',
                'title' => 'عميل جديد',
                'body' => 'تم تسجيل العميل «مؤسسة النور التجارية» بنجاح.',
                'time' => 'قبل 25 دقيقة',
                'read' => false,
            ],
            [
                'id' => 3,
                'type' => 'ticket',
                'title' => 'تذكرة دعم فني جديدة',
                'body' => 'تذكرة رقم #1042 بانتظار الرد من فريق الدعم.',
                'time' => 'قبل ساعة',
                'read' => false,
            ],
            [
                'id' => 4,
                'type' => 'warning',
                'title' => 'محاولة دخول فاشلة',
                'body' => 'تم رصد 3 محاولات دخول فاشلة على حساب أحد المستخدمين.',
                'time' => 'قبل 3 ساعات',
                'read' => true,
            ],
            [
                'id' => 5,
                'type' => 'info',
                'title' => 'تحديث النظام',
                'body' => 'تم تحديث لوحة التحكم إلى الإصدار الأحدث بنجاح.',
                'time' => 'أمس',
                'read' => true,
            ],
        ];
    }

    /**
     * عدد الإشعارات غير المقروءة (لشارة الجرس في الهيدر).
     */
    public static function unreadCount(): int
    {
        return collect(self::demo())->where('read', false)->count();
    }
}
