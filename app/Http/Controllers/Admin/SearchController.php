<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Item;
use App\Models\Supplier;
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
                $results = Item::query()
                    ->where('is_active', true)
                    ->when($query, function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                          ->orWhere('code', 'like', "%{$query}%");
                    })
                    ->with('category:id,name')
                    ->orderBy('name')
                    ->paginate(25, ['id', 'name', 'code', 'category_id', 'price', 'tax_rate']);
                
                // Format items to include category name at top level for easier UI binding
                $results->getCollection()->transform(function ($item) {
                    $array = $item->toArray();
                    $array['category'] = $item->category?->name;
                    return $array;
                });
                break;
                
            default:
                return response()->json(['message' => 'Invalid search type'], 400);
        }

        return response()->json($results);
    }
}
