<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrderReceiptController extends Controller
{
    public function __invoke(Request $request, Order $order): View
    {
        Gate::authorize('view', $order);

        $order->load([
            'tenant',
            'branch',
            'customer',
            'creator',
            'items',
            'payments' => fn ($query) => $query->with('receiver')->orderBy('paid_at')->orderBy('id'),
        ]);

        abort_unless(
            $order->tenant?->hasActiveSubscription(),
            403,
            'Masa aktif langganan bisnis Anda telah berakhir.',
        );

        return view('orders.receipt', [
            'order' => $order,
            'size' => $request->query('size') === 'a4' ? 'a4' : 'thermal',
        ]);
    }
}
