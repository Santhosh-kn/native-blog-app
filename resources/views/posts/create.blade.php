@extends('layouts.app')

@section('title', 'New Post')

@section('content')
    <a href="{{ route('camera.capture') }}" class="btn btn-secondary btn-block" style="margin-bottom: 12px;">📷 Take Photo</a>

    <a href="{{ route('camera.pick') }}" class="btn btn-secondary btn-block" style="margin-bottom: 16px;">Choose from Gallery</a>

    @if ($capturedPhoto)
        <img
            data-native-image="{{ route('camera.preview', ['t' => time()]) }}"
            alt="Selected post image"
            style="width: 100%; border-radius: 10px; margin-bottom: 12px; display: block;"
        >
        @include('partials.native-image-loader')
        <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 16px;">Image selected — fill in the form below.</p>
    @endif

    <form method="POST" action="{{ route('posts.store') }}">
        @csrf
        <div class="field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="{{ old('title') }}">
            @error('title') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="body">Body</label>
            <textarea id="body" name="body">{{ old('body') }}</textarea>
            @error('body') <p class="error">{{ $message }}</p> @enderror
        </div>
        @error('photo') <p class="error">{{ $message }}</p> @enderror
        <button type="submit" class="btn btn-primary btn-block">Publish</button>
    </form>
@endsection
