<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesRetailerScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Models\InventoryLog;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ResolvesRetailerScope;

    public function index(Request $request): JsonResponse
    {
        $retailerId = $this->scopedRetailerId($request);
        $search = $request->string('search')->toString();

        $products = Product::query()
            ->with('category')
            ->where('retailer_id', $retailerId)
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            }))
            ->latest('id')
            ->paginate(50);

        return response()->json($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['retailer_id'] = $this->scopedRetailerId($request);

        $product = Product::query()->create($data);

        return response()->json($product, 201);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $this->ensureRetailerResourceAccess($request, $product->retailer_id);

        return response()->json($product->load(['category', 'synonyms']));
    }

    public function update(StoreProductRequest $request, Product $product): JsonResponse
    {
        $this->ensureRetailerResourceAccess($request, $product->retailer_id);

        $data = $request->validated();
        $data['retailer_id'] = $product->retailer_id;

        $product->update($data);

        return response()->json($product);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensureRetailerResourceAccess($request, $product->retailer_id);

        $product->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * Adjust stock (+/-) and log the change.
     */
    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $this->ensureRetailerResourceAccess($request, $product->retailer_id);

        $validated = $request->validate([
            'adjustment' => ['required', 'integer'],   // positive = add, negative = remove
            'reason'     => ['nullable', 'string', 'max:255'],
        ]);

        $before = $product->stock;
        $after  = max(0, $before + $validated['adjustment']);

        $product->update(['stock' => $after]);

        InventoryLog::create([
            'product_id'      => $product->id,
            'retailer_id'     => $product->retailer_id,
            'delta'           => $after - $before,
            'resulting_stock' => $after,
            'reason'          => $validated['reason'] ?? 'manual_adjustment',
            'user_id'         => $request->user()?->id,
        ]);

        return response()->json(['product' => $product->fresh(), 'stock_before' => $before, 'stock_after' => $after]);
    }
}

