<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * لكل قسمٍ رقم واتساب واشتراكه: المسابح تراسل باسم مؤسستها، وكل قاعة برقمها.
 *
 * الحساب يُسند إلى وحدةٍ بعينها (قاعة) أو إلى قسمٍ كامل (المسابح)، والرسالة
 * تخرج من حساب وحدتها إن وُجد، وإلا من حساب قسمها، وإلا من البوابة العامة
 * في .env كما كانت. ورمز الوصول مشفّر بمفتاح التطبيق، فنسخة القاعدة المستعادة
 * في بيئةٍ أخرى لا تحمل ما يُرسَل به من رقم العميل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            // الاسم الذي تُوقَّع به الرسائل ({business_name}) ويُعرض في السجل.
            $table->string('name');
            // مفتاح ActivitySegment: halls | chalets | pools.
            $table->string('section')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('driver', 30)->default('cwts');
            $table->string('base_url')->nullable();
            $table->string('instance_id')->nullable();
            $table->text('access_token')->nullable();
            $table->string('wa_number', 30)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['section', 'unit_id']);
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            // فارغ = أُرسلت من البوابة العامة.
            $table->foreignId('whatsapp_account_id')->nullable()->after('sent_by')
                ->constrained('whatsapp_accounts')->nullOnDelete();
        });

        $this->seedAccounts();
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_account_id');
        });

        Schema::dropIfExists('whatsapp_accounts');
    }

    /** حسابات النشاط الثلاثة، بلا أرقام: تُكتب وتُربط من شاشة الإعدادات. */
    private function seedAccounts(): void
    {
        $now = now();

        $accounts = [
            ['name' => 'مؤسسة العجلان لبرك السباحه', 'section' => 'pools', 'unit_id' => null],
        ];

        // الأسماء كما كُتبت في شاشة الوحدات قد تحمل مسافةً زائدة، فتُقارَن بعد طيّها.
        $squash = fn (string $name) => preg_replace('/\s+/u', ' ', trim($name));
        $units = DB::table('units')->whereNull('deleted_at')->get(['id', 'name'])
            ->mapWithKeys(fn ($unit) => [$squash($unit->name) => $unit->id]);

        foreach (['قاعة ديوان المسره', 'قاعة ياسمين الشام'] as $hall) {
            if ($unitId = $units[$hall] ?? null) {
                $accounts[] = ['name' => $hall, 'section' => 'halls', 'unit_id' => $unitId];
            }
        }

        foreach ($accounts as $order => $account) {
            DB::table('whatsapp_accounts')->insert($account + [
                'driver' => 'cwts',
                'is_active' => true,
                'sort_order' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
