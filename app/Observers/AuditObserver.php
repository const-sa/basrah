<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * كاتب السجل الرقابي — يرصد إنشاء السجلات الحساسة وتعديلها وحذفها.
 *
 * المراقب يُسجَّل مركزيًا في AppServiceProvider على الأنواع المذكورة في
 * AuditLog::SUBJECTS، فلا يحتاج كل نموذج إلى سطرٍ فيه.
 *
 * وفشل الكتابة لا يُسقط العملية: تعذُّر تسجيل أن حجزًا أُنشئ لا يبرر رفض
 * الحجز. يُكتب الخطأ في السجلات ويمضي العمل.
 */
class AuditObserver
{
    public function created(Model $model): void
    {
        $this->write('created', $model, null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());

        // الحفظ بلا تغيير فعلي لا يُسجَّل — وإلا امتلأ السجل بصفوف فارغة.
        if ($changes === []) {
            return;
        }

        $before = array_intersect_key($this->clean($model->getRawOriginal()), $changes);

        // الاسترجاع يمرّ من هنا: restore() يحفظ النموذج فيُطلق updated
        // بعمود deleted_at صار null. وهو استرجاعٌ في نظر المراجع لا تعديلًا.
        //
        // أما الحذف الناعم نفسه فلا يمرّ هنا: runSoftDelete يكتب العمود
        // باستعلام مباشر لا يُطلق updated، ويُطلق deleted وحده — فيلتقطه
        // الخطّاف أدناه. وإن كُتب العمود يدويًا بـupdate فهو حذفٌ لم يمرّ
        // بـdeleted، فيُسجَّل هنا ولا يتكرر.
        if ($this->softDeletes($model)) {
            $column = $model->getDeletedAtColumn();

            if (array_key_exists($column, $changes)) {
                $this->write(
                    $changes[$column] === null ? 'restored' : 'deleted',
                    $model,
                    $before,
                    $changes,
                );

                return;
            }
        }

        $this->write('updated', $model, $before, $changes);
    }

    /**
     * يُطلق للحذف الناعم والنهائي معًا — وهو المسار الوحيد لكليهما، لأن
     * runSoftDelete لا يُطلق updated. فلا ازدواج هنا مع الخطّاف أعلاه.
     */
    public function deleted(Model $model): void
    {
        $this->write('deleted', $model, $this->clean($model->getRawOriginal()), null);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function write(string $event, Model $model, ?array $old, ?array $new): void
    {
        try {
            $request = request();
            $user = Auth::user();

            AuditLog::create([
                'user_id' => $user?->getKey(),
                'actor_name' => $user?->name ?? 'النظام',
                'event' => $event,
                'auditable_type' => $model::class,
                'auditable_id' => $model->getKey(),
                'auditable_label' => $this->label($model),
                'old_values' => $old,
                'new_values' => $new,
                'ip_address' => $request?->ip(),
                'url' => $request ? mb_substr($request->fullUrl(), 0, 255) : null,
                'method' => $request?->method(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (Throwable $e) {
            Log::error('تعذّر كتابة السجل الرقابي', [
                'event' => $event,
                'model' => $model::class,
                'id' => $model->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * وصف السجل كما يفهمه المراجع.
     *
     * الاسم يُقرأ من الأعمدة الخام لا بـ `$model->x`: القيد المحاسبي له علاقة
     * `reference` من نوع MorphTo، فكان قراءتها تُرجِع نموذجًا كاملًا يتحوّل
     * نصًّا إلى JSON ويتجاوز طول العمود، فيسقط سجل المراجعة لكل قيد — أي لكل
     * فاتورة ودفعة. والقصّ الأخير يحمي العمود من أي اسم طويل مهما كان مصدره.
     */
    private function label(Model $model): string
    {
        $subject = AuditLog::SUBJECTS[$model::class] ?? class_basename($model);
        $attributes = $model->getAttributes();

        $name = null;

        foreach (['reference', 'number', 'name'] as $key) {
            $value = $attributes[$key] ?? null;

            if (is_scalar($value) && filled($value)) {
                $name = (string) $value;

                break;
            }
        }

        $name ??= $model->getKey() !== null ? '#'.$model->getKey() : '';

        return mb_substr(trim($subject.' '.$name), 0, 255);
    }

    /**
     * تنقية القيم: حذف الحقول السرية وتسطيح ما ليس قابلًا للتخزين نصًا.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function clean(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (in_array($key, AuditLog::REDACTED, true)) {
                // وجود المفتاح يُثبت أن السر تغيّر، وقيمته لا تُنسخ.
                $clean[$key] = '***';

                continue;
            }

            $clean[$key] = match (true) {
                $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
                is_scalar($value), is_null($value), is_array($value) => $value,
                default => (string) $value,
            };
        }

        return $clean;
    }

    private function softDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
