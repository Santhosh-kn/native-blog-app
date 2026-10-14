@extends('layouts.app')
@section('title', 'Welcome')
@section('hide-intro', 'yes')
@section('content')
    <section class="home-hero">
        <p class="eyebrow">Your ideas. Your device.</p>
        <h2>A little space<br>for big ideas.</h2>
        <p>Write posts, work with your files and explore what your phone can do.</p>
        <div class="actions">
            @auth
                <a href="{{ route('home') }}" class="btn">Open your workspace @include('partials.ui-icon', ['name' => 'arrow'])</a>
            @else
                <a href="{{ route('login') }}" class="btn">Sign in @include('partials.ui-icon', ['name' => 'arrow'])</a>
                <a href="{{ route('register') }}" class="btn hero-secondary">Create account</a>
            @endauth
        </div>
    </section>
    <div class="stack">
        <section class="card"><h2 class="card-heading">@include('partials.ui-icon', ['name' => 'edit']) Make it yours</h2><p>Save your thoughts and add a photo to tell the whole story.</p></section>
        <section class="card"><h2 class="card-heading">@include('partials.ui-icon', ['name' => 'file']) Bring your files along</h2><p>Choose documents, preview PDFs and manage file transfers.</p></section>
    </div>
@endsection
