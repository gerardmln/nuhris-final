@extends('admin.layout')

@section('title', 'Edit Employee')
@section('page_title', 'Edit Employee')

@section('content')
    @php
        $cancelRoute = route('admin.employees.index');
    @endphp

    <div class="p-6">
        <form method="POST" action="{{ route('admin.employees.update', $employee) }}" data-employee-form>
            @csrf
            @method('PUT')

            @include('hr.employees._form')
        </form>
    </div>
@endsection

@push('scripts')
    @include('partials.employee-form-rules-script')
@endpush
