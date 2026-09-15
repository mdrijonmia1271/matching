<x-mail::message>
# Thank you for your order, {{ $order->customer_name }}!

Your order **{{ $order->order_number }}** was placed on {{ $order->created_at->format('d M Y, g:i a') }} and is now **{{ $order->status }}**.

<x-mail::table>
| Product | Qty | Price |
|:--------|:---:|------:|
@foreach($order->items as $item)
| {{ $item->product_name }}{{ $item->variant_label ? ' (' . $item->variant_label . ')' : '' }} | {{ $item->quantity }} | {{ \App\Support\Money::format($item->subtotal) }} |
@endforeach
</x-mail::table>

**Subtotal:** {{ \App\Support\Money::format($order->subtotal) }}
@if($order->discount > 0)
**Discount{{ $order->coupon_code ? ' (' . $order->coupon_code . ')' : '' }}:** -{{ \App\Support\Money::format($order->discount) }}
@endif
**Shipping:** {{ $order->shipping_cost > 0 ? \App\Support\Money::format($order->shipping_cost) : 'Free' }}
**Total:** {{ \App\Support\Money::format($order->total) }}

**Payment:** {{ $order->payment_method === 'cod' ? 'Cash on delivery' : 'Online payment' }} ({{ $order->payment_status }})

**Delivering to:**
{{ $order->customer_name }}
{{ $order->shipping_address }}{{ $order->shipping_city ? ', ' . $order->shipping_city : '' }}
{{ $order->customer_phone }}

<x-mail::button :url="$url">
View your order
</x-mail::button>

Thanks for shopping with us,<br>
{{ \App\Support\Settings::get('store_name') }}
</x-mail::message>
