<?php

namespace App\Services\Whatsapp;

/**
 * جواب سؤال «هل هذا الرقم مسجَّل في واتساب؟» موحَّداً بين البوابات.
 *
 * ثلاثة أجوبة لا اثنان: نعم، ولا، و«تعذّر معرفة ذلك» — لأن البوابة لا تملك
 * الخدمة أو لأن النداء أخفق. وسؤالٌ بلا جواب ليس نفياً.
 */
class WhatsappNumberCheck
{
    /** @param  bool|null  $exists  فارغ حين لم تُجب البوابة. */
    private function __construct(
        protected string $driver,
        protected string $phone,
        protected ?bool $exists = null,
        protected bool $supported = true,
        protected ?string $error = null,
        protected ?string $code = null
    ) {}

    public static function registered(string $driver, string $phone): self
    {
        return new self($driver, $phone, true);
    }

    public static function notRegistered(string $driver, string $phone): self
    {
        return new self($driver, $phone, false);
    }

    /** البوابة لا تملك خدمة فحص أصلاً، فلا شيء يُقال. */
    public static function unsupported(string $driver, string $phone): self
    {
        return new self($driver, $phone, null, false);
    }

    public static function failed(string $driver, string $phone, string $error, ?string $code = null): self
    {
        return new self($driver, $phone, null, true, $error, $code);
    }

    /** هل أجابت البوابة بنعم أو لا أصلاً؟ */
    public function answered(): bool
    {
        return $this->exists !== null;
    }

    public function exists(): bool
    {
        return $this->exists === true;
    }

    /** نفيٌ صريح، لا سؤالاً بلا جواب. */
    public function missing(): bool
    {
        return $this->exists === false;
    }

    /** هل تستطيع البوابة فحص الأرقام أصلاً؟ */
    public function supported(): bool
    {
        return $this->supported;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /** رمز الإخفاق كما تسمّيه البوابة، مثل no_session_available. */
    public function code(): ?string
    {
        return $this->code;
    }

    public function driver(): string
    {
        return $this->driver;
    }
}
