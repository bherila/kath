@extends('layouts.app')

@section('content')
  <div id="blog"></div>
@endsection

@push('scripts')
  @vite(['resources/js/blog.tsx'])
@endpush
