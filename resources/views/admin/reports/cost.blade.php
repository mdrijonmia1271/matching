@extends($printing ? 'layouts.report-print' : 'layouts.admin')

@section('title', 'Cost report')
@section('heading', 'Cost report')
@section('filters', ($types[request('type')] ?? 'All types') . ' · ' . ($accounts->firstWhere('id', (int) request('account'))?->name ?? 'All accounts'))

@section('content')
    @include('admin.reports.partials.ledger', [
        'totalLabel' => 'Total cost',
        'color' => 'text-rose-600',
        'hint' => 'All money paid out; transfers between accounts left out',
        'empty' => 'No money went out during this period.',
    ])
@endsection
