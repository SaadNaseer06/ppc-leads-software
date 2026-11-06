@extends('pay.layout')

@section('title', 'Invoice not found')

@section('content')
    <main class="plain">
        <div class="card section" style="text-align: center; padding: 44px 28px">
            <h1>This payment link isn't valid</h1>
            <p class="muted" style="margin-bottom: 0">Please check the link, or ask the person who sent it for a new one.</p>
        </div>
    </main>
@endsection
