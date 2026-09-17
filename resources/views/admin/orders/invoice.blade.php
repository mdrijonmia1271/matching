@php
    use App\Support\Money;
    use App\Support\Settings;

    $store = Settings::get('store_name');
    $due = $order->due_amount;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order->order_number }} — {{ $store }}</title>
    <style>
        @page { size: A4; margin: 12mm; }

        * { box-sizing: border-box; }

        body { margin: 0; padding: 24px 16px; background: #e2e8f0; color: #0f172a; font-family: Arial, Helvetica, sans-serif; font-size: 13px; }

        .toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; max-width: 190mm; margin: 0 auto 16px; padding: 12px 16px; border-radius: 8px; background: #0f172a; color: #fff; }
        .toolbar button { border: 0; border-radius: 6px; padding: 8px 18px; background: #7c3aed; color: #fff; font-weight: 600; cursor: pointer; }
        .toolbar a { color: #cbd5e1; text-decoration: none; }
        .toolbar a:hover { text-decoration: underline; }

        .sheet { max-width: 190mm; margin: 0 auto; padding: 14mm; background: #fff; }

        .head { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 14px; border-bottom: 2px solid #0f172a; }
        .head h1 { margin: 0 0 4px; font-size: 22px; }
        .head .meta { text-align: right; font-size: 12px; color: #475569; }
        .head .meta strong { display: block; font-size: 15px; color: #0f172a; }
        .muted { color: #64748b; }

        .parties { display: flex; justify-content: space-between; gap: 24px; margin: 16px 0; }
        .parties h2 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }

        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { padding: 8px 6px; border-bottom: 1px solid #cbd5e1; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
        td { padding: 8px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .right { text-align: right; }
        .sku { font-family: monospace; font-size: 11px; color: #94a3b8; }

        .totals { width: 58%; margin-left: auto; margin-top: 12px; }
        .totals td { border: 0; padding: 4px 6px; }
        .totals .grand td { border-top: 2px solid #0f172a; font-size: 16px; font-weight: 700; padding-top: 8px; }
        .totals .due td { color: #b91c1c; font-weight: 700; }

        .payments { margin-top: 18px; }
        .payments h2 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }

        .foot { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 12px; color: #64748b; }

        @media print {
            body { padding: 0; background: #fff; }
            .toolbar { display: none; }
            .sheet { max-width: none; padding: 0; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button onclick="window.print()">Print</button>
    <a href="{{ route('admin.orders.show', $order) }}">Order details</a>
    @can('pos.sell')
        <a href="{{ route('admin.pos.index') }}">Back to the counter</a>
    @endcan
    <span class="muted" style="margin-left:auto">Set the printer to A4 and 100% scale.</span>
</div>

<div class="sheet">
    <div class="head">
        <div>
            <h1>{{ $store }}</h1>
            <p class="muted" style="margin:0">
                {{ Settings::get('store_address') ?: '' }}
                @if(Settings::get('store_phone')) <br>{{ Settings::get('store_phone') }} @endif
                @if(Settings::get('store_email')) <br>{{ Settings::get('store_email') }} @endif
            </p>
        </div>
        <div class="meta">
            <strong>{{ $order->isPosSale() ? 'Sales receipt' : 'Invoice' }}</strong>
            {{ $order->order_number }}<br>
            {{ $order->created_at?->format('d M Y, g:i a') }}<br>
            {{ $order->payment_method_label }} · {{ $order->payment_status_label }}
        </div>
    </div>

    <div class="parties">
        <div>
            <h2>Customer</h2>
            <p style="margin:0">
                <strong>{{ $order->customer_name }}</strong><br>
                @if($order->customer_phone)<span class="muted">{{ $order->customer_phone }}</span><br>@endif
                @if($order->customer_email)<span class="muted">{{ $order->customer_email }}</span><br>@endif
                @if($order->shipping_address)
                    <span class="muted">{{ $order->shipping_address }}{{ $order->shipping_city ? ', ' . $order->shipping_city : '' }}</span>
                @endif
            </p>
        </div>
        <div style="text-align:right">
            <h2>Served by</h2>
            <p style="margin:0">{{ auth()->user()->name }}</p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="right" style="width:60px">Qty</th>
                <th class="right" style="width:90px">Price</th>
                <th class="right" style="width:100px">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td>
                        {{ $item->product_name }}{{ $item->variant_label ? ' — ' . $item->variant_label : '' }}
                        @if($item->sku)<br><span class="sku">{{ $item->sku }}</span>@endif
                    </td>
                    <td class="right">{{ number_format($item->quantity) }}</td>
                    <td class="right">{{ Money::format((float) $item->price) }}</td>
                    <td class="right">{{ Money::format((float) $item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ Money::format((float) $order->subtotal) }}</td></tr>
        @if((float) $order->discount > 0)
            <tr><td>Discount{{ $order->coupon_code ? ' (' . $order->coupon_code . ')' : '' }}</td><td class="right">− {{ Money::format((float) $order->discount) }}</td></tr>
        @endif
        @if((float) $order->shipping_cost > 0)
            <tr><td>Delivery</td><td class="right">{{ Money::format((float) $order->shipping_cost) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="right">{{ Money::format((float) $order->total) }}</td></tr>
        <tr><td>Paid</td><td class="right">{{ Money::format((float) $order->paid_amount) }}</td></tr>
        @if($due > 0)
            <tr class="due"><td>Due</td><td class="right">{{ Money::format($due) }}</td></tr>
        @endif
    </table>

    @if($order->payments->where('status', 'success')->isNotEmpty())
        <div class="payments">
            <h2>Payments</h2>
            <table>
                <tbody>
                    @foreach($order->payments->where('status', 'success') as $payment)
                        <tr>
                            <td>{{ $payment->paid_at?->format('d M Y, g:i a') }}</td>
                            <td>{{ config('shop.payment_methods')[$payment->method] ?? ucfirst((string) $payment->method) }}{{ $payment->account ? ' → ' . $payment->account->name : '' }}</td>
                            <td class="right">{{ Money::format((float) $payment->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($order->note)
        <p class="muted" style="margin-top:16px">{{ $order->note }}</p>
    @endif

    <div class="foot">{{ Settings::get('invoice_note') }}</div>
</div>

</body>
</html>
