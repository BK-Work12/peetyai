<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesRetailerScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ResolvesRetailerScope;

    public function index(Request $request): JsonResponse
    {
        $retailerId = $this->scopedRetailerId($request);

        $customers = Customer::query()
            ->withCount('orders')
            ->withSum('orders', 'total')
            ->where('retailer_id', $retailerId)
            ->latest('id')
            ->paginate(25);

        return response()->json($customers);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['retailer_id'] = $this->scopedRetailerId($request);

        $customer = Customer::query()->create($data);

        return response()->json($customer, 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->ensureRetailerResourceAccess($request, $customer->retailer_id);

        return response()->json($customer->load([
            'orders.items',
            'messages' => fn ($query) => $query
                ->whereNotNull('body')
                ->latest('id')
                ->limit(200),
        ]));
    }
}
