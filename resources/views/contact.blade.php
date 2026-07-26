@extends('layouts.app')

@section('content')
  <div id="contact"></div>
@endsection

@push('scripts')
  @vite(['resources/js/contact.tsx'])
@endpush
