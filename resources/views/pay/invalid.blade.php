@extends('pay.layout')

@section('title', 'Invoice not found')

@section('content')
    <div class="card">
        <div class="section" style="text-align: center; padding: 48px 24px">
            <div class="brand">This payment link isn't valid</div>
            <p class="muted">Please check the link, or ask the person who sent it for a new one.</p>
        </div>
    </div>
@endsection
