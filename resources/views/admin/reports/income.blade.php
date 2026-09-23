@extends($printing ? 'layouts.report-print' : 'layouts.admin')

@section('title', 'Income report')
@section('heading', 'Income report')
@section('filters', ($types[request('type')] ?? 'All types') . ' · ' . ($accounts->firstWhere('id', (int) request('account'))?->name ?? 'All accounts'))

@section('content')
    @include('admin.reports.partials.ledger', [
        'totalLabel' => 'Total income',
        'color' => 'text-emerald-600',
        'hint' => 'All money received; transfers between accounts left out',
        'empty' => 'No money came in during this period.',
    ])
@endsection
