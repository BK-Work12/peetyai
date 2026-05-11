<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesRetailerScope;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    use ResolvesRetailerScope;

    /**
     * Return a list of customers with their most recent message (inbox view).
     */
    public function index(Request $request): JsonResponse
    {
        $retailerId = $this->scopedRetailerId($request);

        $customers = Customer::query()
            ->where('retailer_id', $retailerId)
            ->whereHas('messages')
            ->withCount('messages')
            ->with([
                'messages' => fn ($q) => $q
                    ->whereNotNull('body')
                    ->latest('id')
                    ->limit(1),
            ])
            ->orderByDesc(
                Message::query()
                    ->select('created_at')
                    ->whereColumn('customer_id', 'customers.id')
                    ->latest('id')
                    ->limit(1)
            )
            ->paginate(50);

        // Flatten latest message into each customer row
        $customers->getCollection()->transform(function ($c) {
            $latest = $c->messages->first();
            $c->latest_message = $latest ? [
                'id'         => $latest->id,
                'body'       => $latest->body,
                'direction'  => $latest->direction,
                'created_at' => $latest->created_at,
            ] : null;
            unset($c->messages);
            return $c;
        });

        return response()->json($customers);
    }

    /**
     * Return full conversation thread for a specific customer.
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->ensureRetailerResourceAccess($request, $customer->retailer_id);

        $messages = Message::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('body')
            ->oldest('id')
            ->get(['id', 'direction', 'body', 'created_at']);

        return response()->json([
            'customer' => $customer->only(['id', 'name', 'phone', 'address']),
            'messages' => $messages,
        ]);
    }
}
