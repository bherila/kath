@extends('layouts.app')

@section('content')
  <script type="application/json" id="wedding-bootstrap">@json($bootstrap)</script>
  <div id="wedding"></div>
@endsection

@push('scripts')
  @vite(['resources/js/wedding.tsx'])
@endpush
