<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemOptionResource;
use App\Models\Client;
use App\Models\Item;
use App\Models\Supplier;
use App\Support\ClientType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        $query = $request->string('q')->toString();

        switch ($type) {
            case 'clients':
                $results = Client::query()
                    // سجلّ النشاط حين تطلبه الشاشة: عرض معدات المسبح يُقدَّم
                    // لعميل مسبح، وعميل القاعة في قائمته اختيارٌ خاطئ ينتظر
                    // أن يُفعَل. وبلا المعامل يبقى البحث في الدليل كلّه كما كان.
                    ->ofType($this->register($request))
                    ->when($query, function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                            ->orWhere('mobile', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%");
                    })
                    ->orderByDesc('is_walk_in')
                    ->orderBy('name')
                    ->paginate(25, ['id', 'name', 'mobile']);
                break;

            case 'suppliers':
                $results = Supplier::query()
                    ->when($query, function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                            ->orWhere('mobile', 'like', "%{$query}%");
                    })
                    ->orderBy('name')
                    ->paginate(25, ['id', 'name', 'mobile']);
                break;

            case 'items':
                // The picker appends whatever this returns straight onto an
                // invoice line, so the payload has to be the one shape those
                // forms already expect — hence ItemOptionResource, not a
                // hand-picked column list that drifts from it.
                //
                // The category comes from `item_category_id`; naming it
                // `category_id` here made every item search answer 500 and
                // left the quotation and purchase forms unable to add a line.
                $results = Item::query()
                    ->where('is_active', true)
                    ->when($query, fn ($q) => $q->where(fn ($w) => $w
                        ->where('name', 'like', "%{$query}%")
                        ->orWhere('code', 'like', "%{$query}%")
                        ->orWhere('barcode', 'like', "%{$query}%")))
                    ->with('category:id,name')
                    ->orderBy('name')
                    ->paginate(25, ['id', 'name', 'code', 'item_category_id', 'cost', 'price', 'tax_rate']);

                // The purchase form prices its lines by cost; the sales side
                // never sees it.
                $withCost = $request->boolean('with_cost');

                $results->setCollection(
                    collect(ItemOptionResource::list($results->getCollection(), $withCost)),
                );
                break;

            default:
                return response()->json(['message' => 'Invalid search type'], 400);
        }

        return response()->json($results);
    }

    /**
     * سجلّات العملاء المطلوبة في ?register=pool أو ?register=hall,chalet.
     *
     * ما لا يُعرف من الأنواع يُطرح لا يُرفض الطلب كلّه: معاملٌ مكتوبٌ خطأً
     * يردّ الدليل كاملًا كما كان قبل هذا المعامل، ولا يترك الشاشة فارغة.
     *
     * @return list<string>
     */
    private function register(Request $request): array
    {
        $asked = array_filter(array_map(
            'trim',
            explode(',', $request->string('register')->toString()),
        ));

        return array_values(array_intersect($asked, ClientType::keys()));
    }
}
