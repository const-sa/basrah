<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * The filter values as a screen must receive them back.
 *
 * $request->only() returns the keys the query string happens to carry and no
 * others, which on first load is none of them. A <select> bound to a value it
 * holds no option for renders as an empty box — not «كل الأنواع», not «كل
 * الأقسام», just a blank the operator has to open to find out what it does. A
 * cleared filter arrives the same way by a different route: the page posts its
 * whole filter object back, so a null returns as type=, and an empty string is
 * no more selectable than a missing key.
 */
trait StatesFilters
{
    /**
     * Every key stated, a cleared one as null, and a key ending in _id as an
     * int — the option carries the id as a number, and the string "3" standing
     * beside it selects nothing.
     *
     * @param  list<string>  $keys
     * @return array<string, string|int|null>
     */
    protected function filterState(Request $request, array $keys): array
    {
        return collect($keys)
            ->mapWithKeys(function (string $key) use ($request) {
                if (str_ends_with($key, '_id')) {
                    return [$key => $request->integer($key) ?: null];
                }

                $value = $request->string($key)->toString();

                return [$key => $value === '' ? null : $value];
            })
            ->all();
    }
}
