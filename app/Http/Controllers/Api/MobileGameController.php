<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Club;
use Illuminate\Http\Request;

class MobileGameController extends Controller
{
    /** Справочник клубов для создания игры (активные, опц. фильтр по городу). */
    public function clubs(Request $request)
    {
        $user = $request->user();

        $query = Club::active()->notTest();
        if (!empty($user->city)) {
            $query->where('city', $user->city);
        }

        $clubs = $query->orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'address' => $c->address,
            'city' => $c->city,
        ]);

        return response()->json(['success' => true, 'data' => $clubs]);
    }
}
