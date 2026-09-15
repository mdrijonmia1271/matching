<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Barcode labels — {{ $storeName }}</title>
    <style>
        @page {
            size: {{ $size['sheet'] ? 'A4' : $size['width'] . 'mm ' . $size['height'] . 'mm' }};
            margin: {{ $size['sheet'] ? '10mm 0 0 0' : '0' }};
        }

        * { box-sizing: border-box; }

        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #e2e8f0; }

        .toolbar { position: sticky; top: 0; z-index: 1; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 16px; background: #0f172a; color: #fff; font-size: 14px; }
        .toolbar button { border: 0; border-radius: 6px; padding: 8px 18px; background: #7c3aed; color: #fff; font-weight: 600; cursor: pointer; }
        .toolbar span { color: #cbd5e1; }

        .labels { display: flex; flex-wrap: wrap; gap: 4mm; padding: 16px; }
        .labels.sheet { display: grid; grid-template-columns: repeat(3, {{ $size['width'] }}mm); grid-auto-rows: {{ $size['height'] }}mm; gap: 0; width: 210mm; margin: 0 auto; background: #fff; }

        .label {
            width: {{ $size['width'] }}mm;
            height: {{ $size['height'] }}mm;
            padding: 1.2mm 2.5mm;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
            text-align: center;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .labels.sheet .label { outline: 1px dashed #cbd5e1; }

        .store { font-size: 5.5pt; font-weight: 700; letter-spacing: .4pt; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .name { font-size: 6.5pt; font-weight: 700; line-height: 1.15; max-height: 2.3em; overflow: hidden; }
        .variant { font-weight: 400; }
        .bars svg { display: block; width: 100%; height: {{ $size['bars'] }}mm; }
        .code { font-family: 'Courier New', monospace; font-size: 6.5pt; letter-spacing: .6pt; line-height: 1; }
        .meta { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; font-size: 5.5pt; line-height: 1; }
        .meta .sku { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .meta .price { font-size: 8pt; font-weight: 700; white-space: nowrap; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .labels { padding: 0; gap: 0; }
            .labels.sheet { width: auto; }
            .labels.sheet .label { outline: none; }
            .labels:not(.sheet) .label { page-break-after: always; break-after: page; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print {{ count($labels) }} label(s)</button>
        <span>{{ $size['name'] }} &middot; set the printer to this paper size, no margins, 100% scale.</span>
    </div>

    <div class="labels {{ $size['sheet'] ? 'sheet' : '' }}">
        @foreach($labels as $label)
            @php $variant = $label['variant']; @endphp
            <div class="label">
                <div class="store">{{ $storeName }}</div>
                <div class="name">
                    {{ $variant->product?->name }}
                    @if($variant->product?->has_variants)<span class="variant">&middot; {{ $variant->label }}</span>@endif
                </div>
                <div>
                    <div class="bars">{!! $label['svg'] !!}</div>
                    <div class="code">{{ $variant->barcode }}</div>
                </div>
                <div class="meta">
                    <span class="sku">{{ $variant->sku }}</span>
                    @if($showPrice)
                        <span class="price">{{ \App\Support\Money::format($variant->current_price, false) }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</body>
</html>
