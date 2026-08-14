@extends('layouts.app')

@section('title', 'Edit Post')

@section('content')
    <form method="POST" action="{{ route('posts.update', $post->id) }}">
        @csrf
        @method('PUT')
        <div class="field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="{{ old('title', $post->title) }}">
            @error('title') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="field">
            <label for="body">Body</label>
            <textarea id="body" name="body">{{ old('body', $post->body) }}</textarea>
            @error('body') <p class="error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
    </form>
@endsection