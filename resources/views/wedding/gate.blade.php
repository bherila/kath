@extends('layouts.app')

@section('content')
  <div class="mx-auto w-full max-w-md py-8">
    <h1 class="text-3xl font-semibold tracking-tight text-center">Katherine &amp; Jack</h1>
    <p class="mt-1 text-center text-muted-foreground">Wedding Hub</p>

    <div class="mt-8 rounded-xl border bg-card p-6 shadow-sm">
      <p class="text-sm text-muted-foreground">
        Enter your email to watch the ceremony and share your photos and videos.
      </p>

      <form method="POST" action="{{ route('wedding.enter') }}" class="mt-6 space-y-4">
        @csrf
        <div class="space-y-1.5">
          <label for="email" class="text-sm font-medium">Email</label>
          <input id="email" name="email" type="email" required autocomplete="email" inputmode="email"
            value="{{ old('email') }}"
            class="flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
          @error('email')
            <p class="text-sm text-destructive">{{ $message }}</p>
          @enderror
        </div>
        <div class="space-y-1.5">
          <label for="name" class="text-sm font-medium">Your name <span class="text-muted-foreground font-normal">(optional, shown on your photos)</span></label>
          <input id="name" name="name" type="text" maxlength="60" autocomplete="name"
            value="{{ old('name') }}"
            class="flex h-11 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
          @error('name')
            <p class="text-sm text-destructive">{{ $message }}</p>
          @enderror
        </div>
        <button type="submit"
          class="inline-flex h-11 w-full items-center justify-center rounded-md bg-primary px-4 font-medium text-primary-foreground shadow hover:bg-primary/90">
          Continue
        </button>
      </form>
    </div>
  </div>
@endsection
