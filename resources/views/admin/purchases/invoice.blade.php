@php
    use App\Support\Money;
    use App\Support\Settings;

    $store = Settings::get('store_name');
    $supplier = $purchase->supplier;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The title becomes the default PDF file name. --}}
    <title>Purchase {{ $purchase->number }} — {{ $store }}</title>
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
        .status { display: inline-block; margin-top: 4px; padding: 2px 8px; border: 1px solid #cbd5e1; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }

        .parties { display: flex; justify-content: space-between; gap: 24px; margin: 16px 0; }
        .parties h2 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }

        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { padding: 8px 6px; border-bottom: 1px solid #cbd5e1; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
        td { padding: 8px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .right { text-align: right; }
        .sku { font-family: monospace; font-size: 11px; color: #94a3b8; }

        .totals { width: 58%; margin-left: auto; margin-top: 12px; }
        .totals td { border: 0; padding: 4px 6px; }
        .totals .grand td { border-top: 2px solid #0f172a; font-size: 16px; font-weight: 700; padding-top: 8px; }

        .signs { display: flex; justify-content: space-between; gap: 48px; margin-top: 56px; }
        .signs div { flex: 1; padding-top: 6px; border-top: 1px solid #94a3b8; text-align: center; font-size: 12px; color: #64748b; }

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
    <button onclick="window.print()">Print / Save as PDF</button>
    <a href="{{ route('admin.purchases.show', $purchase) }}">Purchase details</a>
    <span class="muted" style="margin-left:auto">For a PDF, choose "Save as PDF" as the printer. A4, 100% scale.</span>
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
            <strong>Purchase invoice</strong>
            {{ $purchase->number }}<br>
            Date: {{ $purchase->purchase_date?->format('d M Y') }}<br>
            @if($purchase->invoice_number)Supplier invoice: {{ $purchase->invoice_number }}<br>@endif
            <span class="status">{{ $purchase->status_label }}</span>
        </div>
    </div>

    <div class="parties">
        <div>
            <h2>Supplier</h2>
            <p style="margin:0">
                @if($supplier)
                    <strong>{{ $supplier->name }}</strong><br>
                    @if($supplier->company)<span class="muted">{{ $supplier->company }}</span><br>@endif
                    @if($supplier->phone)<span class="muted">{{ $supplier->phone }}</span><br>@endif
                    @if($supplier->email)<span class="muted">{{ $supplier->email }}</span><br>@endif
                    @if($supplier->address)<span class="muted">{{ $supplier->address }}</span>@endif
                @else
                    <span class="muted">No supplier</span>
                @endif
            </p>
        </div>
        <div style="text-align:right">
            <h2>Prepared by</h2>
            <p style="margin:0">
                {{ $purchase->creator?->name ?? '—' }}
                @if($purchase->received_at)
                    <br><span class="muted">Received {{ $purchase->received_at->format('d M Y, g:i a') }}{{ $purchase->receiver ? ' by ' . $purchase->receiver->name : '' }}</span>
                @endif
            </p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:28px">#</th>
                <th>Item</th>
                <th class="right" style="width:60px">Qty</th>
                <th class="right" style="width:100px">Unit cost</th>
                <th class="right" style="width:110px">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($purchase->items as $item)
                <tr>
                    <td class="muted">{{ $loop->iteration }}</td>
                    <td>
                        {{ $item->variant?->full_name ?? 'Deleted product' }}
                        @if($item->variant?->sku)<br><span class="sku">{{ $item->variant->sku }}</span>@endif
                    </td>
                    <td class="right">{{ number_format($item->quantity) }}</td>
                    <td class="right">{{ Money::format((float) $item->unit_cost) }}</td>
                    <td class="right">{{ Money::format((float) $item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Goods ({{ number_format($purchase->quantity) }} units)</td><td class="right">{{ Money::format((float) $purchase->subtotal) }}</td></tr>
        @if((float) $purchase->discount > 0)
            <tr><td>Discount</td><td class="right">− {{ Money::format((float) $purchase->discount) }}</td></tr>
        @endif
        @if((float) $purchase->additional_cost > 0)
            <tr><td>Additional cost</td><td class="right">+ {{ Money::format((float) $purchase->additional_cost) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="right">{{ Money::format((float) $purchase->total) }}</td></tr>
    </table>

    <div class="signs">
        <div>Received by</div>
        <div>Supplier signature</div>
    </div>

    <div class="foot">Printed {{ now()->format('d M Y, g:i a') }} · {{ $store }}</div>
</div>

</body>
</html>
