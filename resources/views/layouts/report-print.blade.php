{{-- A4 print copy of a report: no sidebar or filters, every row, saved as PDF from the browser's print dialog. --}}
@php $store = \App\Support\Settings::get('store_name'); @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The title becomes the default PDF file name. --}}
    <title>@yield('title') {{ $from->format('d-m-Y') }} to {{ $to->format('d-m-Y') }} — {{ $store }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        /* No fixed paper size: the content follows whatever paper the print dialog picks
           (A4, Letter, a printer's own margins) and the browser can still shrink to fit. */
        @page { margin: 10mm; }

        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* A plain ruled table that always fits between the page margins: every cell
           bordered, text allowed to wrap, so nothing is cut off at the left or right. */
        .print-sheet .card { border: 0 !important; border-radius: 0 !important; box-shadow: none !important; overflow: visible !important; max-width: none !important; }
        .print-sheet table { width: 100% !important; border-collapse: collapse; table-layout: auto; font-family: Arial, Helvetica, sans-serif; font-size: 11px; line-height: 1.35; color: #000; }
        .print-sheet th,
        .print-sheet td { border: 1px solid #000 !important; padding: 4px 5px !important; white-space: normal !important; overflow-wrap: anywhere; vertical-align: top; color: #000 !important; background: transparent !important; }
        /* Amounts and counts never break mid-number; the text columns give way instead. */
        .print-sheet th.text-right,
        .print-sheet td.text-right,
        .print-sheet .sl { white-space: nowrap !important; overflow-wrap: normal; }
        .print-sheet .sl { width: 1%; text-align: center; }
        .print-sheet thead th { background: #e5e7eb !important; font-size: 10px; font-weight: 700; letter-spacing: 0; text-transform: none; }
        .print-sheet tfoot td { font-weight: 700; background: #f3f4f6 !important; }
        .print-sheet td a { color: inherit !important; text-decoration: none !important; font-weight: 400 !important; }
        .print-sheet td span.block { display: block; color: #444 !important; }
        .print-sheet tr { page-break-inside: avoid; border: 0 !important; }
        .print-sheet thead { display: table-header-group; }
        .print-sheet tfoot { display: table-row-group; }

        @media print {
            html, body { width: auto !important; min-width: 0 !important; margin: 0 !important; padding: 0 !important; background: #fff !important; }
            .print-toolbar { display: none !important; }
            /* Exactly the printable width: never wider than the paper, whatever the screen was. */
            .print-sheet { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; box-sizing: border-box; }
            .print-sheet table { max-width: 100% !important; }
        }
    </style>
</head>
<body class="bg-slate-200 font-sans text-slate-800 antialiased">
    <div class="print-toolbar mx-auto flex max-w-[210mm] flex-wrap items-center gap-3 px-4 pt-6">
        <button type="button" onclick="window.print()" class="btn-primary">Print / Save as PDF</button>
        <a href="{{ request()->fullUrlWithQuery(['print' => null]) }}" class="text-sm text-slate-600 hover:underline">Back to the report</a>
        <span class="ml-auto text-xs text-slate-500">For a PDF, choose "Save as PDF" as the printer.</span>
    </div>

    <div class="print-sheet mx-auto my-4 max-w-[210mm] bg-white p-[10mm]">
        <header class="mb-5 flex items-end justify-between gap-4 border-b-2 border-slate-900 pb-3">
            <div>
                <p class="text-lg font-bold text-slate-900">{{ $store }}</p>
                <h1 class="text-base font-semibold text-slate-700">@yield('heading')</h1>
            </div>
            <div class="text-right text-xs text-slate-500">
                <p class="font-semibold text-slate-800">{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</p>
                @hasSection('filters')<p>@yield('filters')</p>@endif
            </div>
        </header>

        @yield('content')

        <footer class="mt-6 border-t border-slate-200 pt-2 text-center text-xs text-slate-400">
            Printed {{ now()->format('d M Y, g:i a') }} by {{ auth()->user()->name }}
        </footer>
    </div>
</body>
</html>
