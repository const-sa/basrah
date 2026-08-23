<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesByUnitType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Facility;
use App\Models\Unit;
use App\Models\User;
use App\Support\BookingPeriod;
use App\Support\StayPeriod;
use App\Support\Weekdays;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إدارة الوحدات (القاعات والشاليهات) وأقسامها.
 */
class UnitsController extends Controller
{
    use AuthorizesByUnitType;

    /**
     * القاعات والشاليهات لكل نوع مدخله الخاص في القائمة، و«الكل» عند غياب المقطع.
     *
     * @param  string|null  $type  halls أو chalets
     */
    public function index(Request $request, ?string $type = null): Response
    {
        $unitType = match ($type) {
            'halls' => 'hall',
            'chalets' => 'chalet',
            default => null,
        };

        // مدخل النوع الواحد يشترط صلاحية ذلك النوع وحده، ومدخل «الكل» يعرض ما
        // يملك المستخدم صلاحية رؤيته منهما — فلا يتسرّب نشاطٌ عبر شاشةٍ جامعة.
        if ($unitType) {
            $this->authorizeUnitAction($request, $unitType, 'view');
        }

        $visibleTypes = collect(['hall' => 'halls.view', 'chalet' => 'chalets.view'])
            ->filter(fn (string $key) => $request->user()?->hasPermission($key))
            ->keys()
            ->all();

        $units = Unit::query()
            ->visibleTo($request->user())
            ->whereIn('type', $visibleTypes)
            ->when($unitType, fn ($q) => $q->where('type', $unitType))
            ->with(['sections.facilities:id,name,icon', 'manager:id,name', 'users:id,name', 'prices'])
            ->withCount(['bookings' => fn ($q) => $q->blocking()])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Unit $u) => [
                'id' => $u->id,
                'code' => $u->code,
                'name' => $u->name,
                'logo_url' => $u->logoUrl(),
                'manager_id' => $u->manager_id,
                'manager_name' => $u->manager?->name,
                'type' => $u->type,
                'bookable_mode' => $u->bookable_mode,
                'privacy_mode' => $u->privacy_mode,
                'capacity' => $u->capacity,
                // Null, not 0 — a unit that asks for no security deposit and
                // one nobody has set an amount for must stay distinguishable.
                'security_deposit' => $u->security_deposit === null ? null : (float) $u->security_deposit,
                'description' => $u->description,
                'notes' => $u->notes,
                'is_active' => $u->is_active,
                'bookings_count' => $u->bookings_count,
                // Unit staff — ids for the form, names for the card.
                'staff_ids' => $u->users->pluck('id')->values(),
                'staff_names' => $u->users->pluck('name')->values(),
                'sections' => $u->sections->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'gender' => $s->gender,
                    'is_active' => $s->is_active,
                    'facility_ids' => $s->facilities->pluck('id')->values(),
                    'facility_names' => $s->facilities->pluck('name')->values(),
                    'shared_facility_ids' => $s->facilities->where('pivot.is_shared', true)->pluck('id')->values(),
                ])->values(),
                // صف لكل (قسم أو الوحدة كاملة) × فترة — تقرؤه نافذة الأسعار مباشرة.
                'prices' => $u->prices->map(fn ($p) => [
                    'unit_section_id' => $p->unit_section_id,
                    'period' => $p->period,
                    'weekday_price' => (float) $p->weekday_price,
                    'weekend_price' => (float) $p->weekend_price,
                    // Null, not 0 — an unset deposit must round-trip as unset.
                    'deposit_amount' => $p->deposit_amount === null ? null : (float) $p->deposit_amount,
                    'deposit_percent' => $p->deposit_percent === null ? null : (float) $p->deposit_percent,
                    // خريطة {رقم اليوم: السعر} — تُرسَل بأيام الأسبوع كلها حتى
                    // لا تفرّق الواجهة بين «يوم لم يُدخَل» و«يوم غائب من الخريطة».
                    'day_prices' => collect(Weekdays::keys())
                        ->mapWithKeys(fn (int $day) => [$day => $p->dayPrice($day)])
                        ->all(),
                ])->values(),
            ]);

        return Inertia::render('admin/units/Index', [
            'units' => $units,
            'options' => self::options(),
            // النوع المعروض — تبني عليه الواجهة العنوان والنموذج، وnull تعني «الكل».
            'type' => $unitType,
            'stats' => [
                'total' => $units->count(),
                'halls' => $units->where('type', 'hall')->count(),
                'chalets' => $units->where('type', 'chalet')->count(),
                'sections' => $units->sum(fn ($u) => count($u['sections'])),
                'active' => $units->where('is_active', true)->count(),
                'inactive' => $units->where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $this->authorizeUnitAction($request, $data['type'], 'create');

        $unit = Unit::create([
            ...collect($data)->except(['sections', 'staff_ids', 'logo'])->all(),
            'logo_path' => $this->storeLogo($request),
            'sort_order' => (Unit::max('sort_order') ?? 0) + 1,
        ]);

        $this->syncSections($unit, $data['sections'] ?? []);
        $this->syncStaff($unit, $data['staff_ids'] ?? []);

        return back()->with('success', 'تم إضافة الوحدة بنجاح');
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeUnitAction($request, $unit, 'edit');

        $data = $this->validated($request, $unit);

        // نقل الوحدة من نوع إلى نوع يلزمه امتلاك النوعين معًا.
        $this->authorizeUnitAction($request, $data['type'], 'edit');

        $payload = collect($data)->except(['sections', 'staff_ids', 'logo'])->all();

        if ($path = $this->storeLogo($request, $unit)) {
            $payload['logo_path'] = $path;
        }

        // إزالة الشعار صراحةً بعلَم منفصل — الحقل الفارغ يعني «لم يُرفع جديد».
        if ($request->boolean('remove_logo')) {
            $this->deleteLogo($unit);
            $payload['logo_path'] = null;
        }

        $unit->update($payload);
        $this->syncSections($unit, $data['sections'] ?? []);
        $this->syncStaff($unit, $data['staff_ids'] ?? []);

        return back()->with('success', 'تم تحديث الوحدة');
    }

    /**
     * تحديث شعار الوحدة وحده — من مساحة العمل مباشرة دون بقية حقول النموذج.
     */
    public function updateLogo(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeUnitAction($request, $unit, 'edit');

        // نطاق الوحدات يُطبَّق هنا كما في مساحة العمل: صلاحية التعديل لا تكفي وحدها.
        if (! $request->user()?->canAccessUnit($unit)) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه الوحدة.');
        }

        $request->validate([
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'remove_logo' => ['boolean'],
        ]);

        if ($request->boolean('remove_logo')) {
            $this->deleteLogo($unit);
            $unit->update(['logo_path' => null]);

            return back()->with('success', 'تم حذف الشعار');
        }

        if (! $path = $this->storeLogo($request, $unit)) {
            return back()->with('warning', 'لم يُرفع أي ملف');
        }

        $unit->update(['logo_path' => $path]);

        return back()->with('success', 'تم تحديث الشعار');
    }

    /**
     * إيقاف الوحدة أو تفعيلها — الموقوفة لا تقبل حجزًا جديدًا.
     */
    public function toggle(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeUnitAction($request, $unit, 'edit');

        $unit->update(['is_active' => ! $unit->is_active]);

        return back()->with(
            'success',
            $unit->is_active
                ? "تم تفعيل «{$unit->name}» وصارت قابلة للحجز"
                : "تم إيقاف «{$unit->name}» — لن تقبل حجوزات جديدة",
        );
    }

    public function destroy(Request $request, Unit $unit): RedirectResponse
    {
        $this->authorizeUnitAction($request, $unit, 'delete');

        // الوحدة المرتبطة بحجوزات لا تُحذف حتى لا يُفقد سجلها المالي والمحاسبي.
        if ($unit->bookings()->exists()) {
            return back()->with('warning', 'لا يمكن حذف وحدة مرتبطة بحجوزات — أوقفها بدل حذفها.');
        }

        $this->deleteLogo($unit);
        $unit->delete();

        return back()->with('success', 'تم حذف الوحدة');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Unit $unit = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('units', 'code')->ignore($unit?->id)],
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'remove_logo' => ['boolean'],
            'manager_id' => ['nullable', 'exists:employees,id'],
            'type' => ['required', Rule::in(['hall', 'chalet'])],
            'bookable_mode' => ['required', Rule::in(['whole', 'sections', 'both'])],
            'privacy_mode' => ['required', Rule::in(['open', 'exclusive'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],

            'staff_ids' => ['array'],
            'staff_ids.*' => ['integer', 'exists:users,id'],

            'sections' => ['array'],
            'sections.*.id' => ['nullable', 'integer', 'exists:unit_sections,id'],
            'sections.*.name' => ['required', 'string', 'max:255'],
            'sections.*.gender' => ['required', Rule::in(['men', 'women', 'mixed'])],
            'sections.*.is_active' => ['boolean'],
            'sections.*.facility_ids' => ['array'],
            'sections.*.facility_ids.*' => ['integer', 'exists:facilities,id'],
            'sections.*.shared_facility_ids' => ['array'],
            'sections.*.shared_facility_ids.*' => ['integer', 'exists:facilities,id'],
        ]);
    }

    /**
     * حفظ الشعار المرفوع وإرجاع مساره، أو null إن لم يُرفع شيء.
     */
    private function storeLogo(Request $request, ?Unit $unit = null): ?string
    {
        if (! $request->hasFile('logo')) {
            return null;
        }

        // الشعار القديم يُحذف حتى لا تتراكم ملفات يتيمة على القرص.
        $this->deleteLogo($unit);

        return $request->file('logo')->store('units/logos', 'public');
    }

    private function deleteLogo(?Unit $unit): void
    {
        if ($unit?->logo_path && Storage::disk('public')->exists($unit->logo_path)) {
            Storage::disk('public')->delete($unit->logo_path);
        }
    }

    /**
     * مزامنة أقسام الوحدة: تحديث الموجود، إضافة الجديد، وحذف ما رُفع من القائمة
     * بشرط ألا يكون مرتبطًا بحجز.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private function syncSections(Unit $unit, array $sections): void
    {
        $keptIds = [];

        foreach ($sections as $i => $section) {
            $model = ! empty($section['id'])
                ? $unit->sections()->findOrNew($section['id'])
                : $unit->sections()->make();

            $model->fill([
                'name' => $section['name'],
                'gender' => $section['gender'],
                'sort_order' => $i,
                'is_active' => $section['is_active'] ?? true,
            ]);

            $model->unit_id = $unit->id;
            $model->save();

            // المرافق: المشترك منها يُعلَّم على الإسناد لا على المرفق نفسه،
            // فالمسبح قد يكون مشتركًا في وحدة ومنفصلًا في أخرى.
            $shared = $section['shared_facility_ids'] ?? [];
            $model->facilities()->sync(
                collect($section['facility_ids'] ?? [])
                    ->mapWithKeys(fn ($id) => [$id => ['is_shared' => in_array($id, $shared)]])
                    ->all(),
            );

            $keptIds[] = $model->id;
        }

        $unit->sections()
            ->whereNotIn('id', $keptIds)
            ->whereDoesntHave('bookings')
            ->delete();
    }

    /**
     * Sync the unit's staff — which is the same thing as the access scope of
     * their accounts, so it is stored in unit_user and nowhere else.
     *
     * Posting someone neither grants nor removes a permission — those come from
     * the account's role — it opens this unit to that account or closes it.
     *
     * @param  list<int>  $staffIds
     */
    private function syncStaff(Unit $unit, array $staffIds): void
    {
        // The form posts multipart for the logo, so ids arrive as strings.
        $staffIds = array_map('intval', $staffIds);

        // The picker never offers super admins: they reach every unit through
        // their role, so a row here would mean nothing. Rows outside what the
        // picker can offer are therefore left alone rather than synced away —
        // a plain sync() would silently delete what the form never showed.
        $offerable = self::staffCandidates()->modelKeys();

        $selected = array_values(array_intersect($staffIds, $offerable));
        $before = $unit->users()->pluck('users.id')->all();

        $attach = array_values(array_diff($selected, $before));
        $detach = array_values(array_intersect(array_diff($before, $selected), $offerable));

        if ($attach) {
            $unit->users()->attach($attach);

            // Posting binds an account to the units it is posted to, so a
            // blanket «sees all units» flag has to go — left standing it would
            // swallow the scope and hand a hall clerk the whole business.
            User::whereIn('id', $attach)->where('has_all_units', true)
                ->update(['has_all_units' => false]);
        }

        if ($detach) {
            $unit->users()->detach($detach);
        }
    }

    /**
     * The accounts the staff picker may offer — everyone but the super admin.
     *
     * @return Collection<int, User>
     */
    private static function staffCandidates()
    {
        return User::query()
            ->whereDoesntHave('role', fn ($q) => $q->where('slug', 'super-admin'))
            ->with('role:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role_id', 'is_active', 'is_demo', 'has_all_units']);
    }

    /**
     * الخيارات الثابتة المعروضة في الواجهة.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'types' => [
                ['key' => 'hall', 'label' => 'قاعة'],
                ['key' => 'chalet', 'label' => 'شاليه'],
            ],
            'bookable_modes' => [
                ['key' => 'whole', 'label' => 'الوحدة كاملة فقط', 'hint' => 'لا تُحجز أقسامها منفردة'],
                ['key' => 'sections', 'label' => 'الأقسام فقط', 'hint' => 'لا تُحجز الوحدة كاملة'],
                ['key' => 'both', 'label' => 'كاملة أو بالأقسام', 'hint' => 'الأشمل — مع منع التعارض الهرمي'],
            ],
            'privacy_modes' => [
                ['key' => 'exclusive', 'label' => 'خصوصية مغلقة', 'hint' => 'حجز أي قسم يحجب بقية الأقسام عن عميل آخر'],
                ['key' => 'open', 'label' => 'أقسام مستقلة', 'hint' => 'يجوز لعميلين مختلفين حجز قسمين في نفس اليوم'],
            ],
            'genders' => [
                ['key' => 'men', 'label' => 'رجال'],
                ['key' => 'women', 'label' => 'نساء'],
                ['key' => 'mixed', 'label' => 'مشترك'],
            ],
            'facilities' => Facility::where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'name', 'icon']),
            'managers' => Employee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // Staff candidates are login accounts, not HR files: the picker
            // decides who may open the unit, and only an account can open
            // anything. Super admins are left out — they already see every unit.
            'staff' => self::staffCandidates()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                // The role is where permissions come from; read-only here so it
                // keeps a single place to be edited.
                'role_name' => $u->role?->name,
                'is_active' => (bool) $u->is_active,
                'is_demo' => (bool) $u->is_demo,
                'sees_all_units' => (bool) $u->has_all_units,
            ])->values(),
            // القاعة تُسعَّر يومًا كاملًا وحده — الصباحي والمسائي للشاليه.
            'periods' => BookingPeriod::hallPeriods(),
            // والشاليه يُسعَّر بالليلة وبفترات اليوم معًا، فله جدوله الخاص.
            'stay_periods' => StayPeriod::pricingPeriods(),
            'weekdays' => Weekdays::forView(),
        ];
    }
}
