@extends('layouts.app')

@section('title', 'Contact')

@section('content')
    <div class="mx-auto max-w-page px-4 py-8 sm:px-6">
        <h1 class="text-2xl font-bold text-slate-900">Contact</h1>

        <div class="card mt-8 space-y-6 p-6 sm:p-8 text-sm leading-relaxed text-slate-700">
            <div>
                <h2 class="font-semibold text-slate-900">Phone</h2>
                <p class="mt-1">
                    <a href="tel:+8801770522500" class="hover:text-brand-600">01770 522500</a><br>
                    <a href="tel:+8801730274949" class="hover:text-brand-600">01730 274949</a>
                </p>
            </div>

            <div>
                <h2 class="font-semibold text-slate-900">Address</h2>
                <p class="mt-1">
                    Ovijan-1/Holding-1, Safiuddin Sarker Academy Road, Auchpara<br>
                    Tongi, Gazipur-1711
                </p>
            </div>
        </div>
    </div>
@endsection
