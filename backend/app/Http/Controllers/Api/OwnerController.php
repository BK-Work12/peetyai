<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\User;
use App\Enums\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OwnerController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $commissionRate = (float) $request->query('commission_rate', 5);
        $gmv = (float) Order::query()->sum('total');

        return response()->json([
            'retailers'            => Retailer::query()->count(),
            'active_retailers'     => Retailer::query()->where('active', true)->count(),
            'orders'               => Order::query()->count(),
            'gmv'                  => $gmv,
            'estimated_commission' => round($gmv * ($commissionRate / 100), 2),
        ]);
    }

    public function retailers(Request $request): JsonResponse
    {
        $retailers = Retailer::query()
            ->withCount('orders')
            ->withSum('orders', 'total')
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json($retailers);
    }

    public function storeRetailer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:120'],
            'email'              => ['required', 'email', 'max:120', 'unique:retailers,email'],
            'phone'              => ['required', 'string', 'max:30'],
            'address'            => ['nullable', 'string', 'max:300'],
            'delivery_radius_km' => ['nullable', 'numeric', 'min:0'],
            'commission_rate'    => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Admin account for this retailer
            'admin_name'     => ['required', 'string', 'max:120'],
            'admin_email'    => ['required', 'email', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
        ]);

        $retailer = Retailer::create([
            'name'               => $validated['name'],
            'slug'               => Str::slug($validated['name']) . '-' . Str::lower(Str::random(5)),
            'email'              => $validated['email'],
            'phone'              => $validated['phone'],
            'address'            => $validated['address'] ?? null,
            'delivery_radius_km' => $validated['delivery_radius_km'] ?? 5,
            'commission_rate'    => $validated['commission_rate'] ?? 5,
            'active'             => true,
        ]);

        $user = User::create([
            'retailer_id' => $retailer->id,
            'name'        => $validated['admin_name'],
            'email'       => $validated['admin_email'],
            'password'    => Hash::make($validated['admin_password']),
            'role'        => UserRole::Retailer,
        ]);

        return response()->json([
            'retailer' => $retailer,
            'admin'    => $user->only(['id', 'name', 'email', 'role']),
        ], 201);
    }

    public function updateRetailer(Request $request, Retailer $retailer): JsonResponse
    {
        $validated = $request->validate([
            'active'             => ['sometimes', 'boolean'],
            'commission_rate'    => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'delivery_radius_km' => ['sometimes', 'numeric', 'min:0'],
            'name'               => ['sometimes', 'string', 'max:120'],
        ]);

        $retailer->update($validated);

        return response()->json(['retailer' => $retailer]);
    }
}

