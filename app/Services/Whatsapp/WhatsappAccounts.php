<?php

namespace App\Services\Whatsapp;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Quotation;
use App\Models\WhatsappAccount;
use App\Support\ActivityPermission;
use App\Support\ActivitySegment;
use App\Support\Letterhead;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * أي رقمٍ تخرج منه رسالة: حساب وحدتها، وإلا حساب قسمها، وإلا البوابة العامة.
 *
 * الحجز وسنده وعقده يعرفون وحدتهم، والعميل لا يعرف إلا نشاطه، فيصل
 * العميل إلى حساب القسم وحده. وحسابٌ موقوف كأنه غير موجود.
 */
class WhatsappAccounts
{
    /** @var Collection<int, WhatsappAccount>|null */
    private ?Collection $active = null;

    public function __construct(private readonly WhatsappManager $whatsapp) {}

    /** الحساب الذي يخصّ هذا السجلّ، أو null للبوابة العامة. */
    public function for(?Model $related): ?WhatsappAccount
    {
        [$unitId, $section] = $this->target($related);

        return $this->resolve($unitId, $section);
    }

    public function resolve(?int $unitId, ?string $section): ?WhatsappAccount
    {
        $accounts = $this->active();

        if ($unitId) {
            $own = $accounts->first(fn (WhatsappAccount $a) => (int) $a->unit_id === $unitId);

            if ($own) {
                return $own;
            }
        }

        if ($section) {
            return $accounts->first(fn (WhatsappAccount $a) => $a->unit_id === null && $a->section === $section);
        }

        return null;
    }

    /** هل ثمّة ما يُرسَل به لهذا السجلّ؟ */
    public function canSend(?Model $related): bool
    {
        $account = $this->for($related);

        return $account ? $account->hasCredentials() : $this->whatsapp->isConfigured();
    }

    /** هل يُرسَل من أي رقمٍ أصلاً — البوابة العامة أو رقم قسم؟ */
    public function anyConfigured(): bool
    {
        return $this->whatsapp->isConfigured()
            || $this->active()->contains(fn (WhatsappAccount $a) => $a->hasCredentials());
    }

    /**
     * الاسم الذي تُوقَّع به الرسالة ({business_name}).
     *
     * بلا رقمٍ للقسم يُوقَّع باسم الجهة صاحبة السجلّ: رسالة المسابح باسم
     * مؤسستها، لا باسم الديوان — جهتان مستقلتان.
     */
    public function senderName(?Model $related): string
    {
        return $this->for($related)?->name
            ?? Letterhead::raw($this->target($related)[1] === ActivitySegment::POOLS)['name'];
    }

    public function forget(): void
    {
        $this->active = null;
    }

    /** @return Collection<int, WhatsappAccount> */
    private function active(): Collection
    {
        return $this->active ??= WhatsappAccount::active()->ordered()->get();
    }

    /**
     * الوحدة والقسم اللذان ينتمي إليهما السجلّ.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function target(?Model $related): array
    {
        $booking = match (true) {
            $related instanceof Booking => $related,
            $related instanceof BookingPayment, $related instanceof Contract => $related->booking,
            default => null,
        };

        if ($booking) {
            $booking->loadMissing('unit');

            return [
                $booking->unit_id ? (int) $booking->unit_id : null,
                ActivityPermission::fromClientType($booking->unit?->type),
            ];
        }

        if ($related instanceof Client) {
            return [null, ActivityPermission::ofClient($related)];
        }

        // عرض السعر: قسمه من قسم العرض — عرض المسابح يخرج من رقم المسابح.
        if ($related instanceof Quotation) {
            return [null, $related->department?->isPools()
                ? ActivitySegment::POOLS
                : ActivityPermission::ofClient($related->client)];
        }

        // عقدٌ بلا حجز (عقود المسابح من عروض الأسعار ونماذجها): قسمه من
        // ترويسته، وإلا من سجلّ عميله.
        if ($related instanceof Contract) {
            return [null, $related->underPoolsLetterhead()
                ? ActivitySegment::POOLS
                : ActivityPermission::ofClient($related->client)];
        }

        return [null, null];
    }
}
