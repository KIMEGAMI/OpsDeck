@props(['href', 'active' => false])

<a href="{{ $href }}" @if ($active) class="is-active" aria-current="page" @endif>{{ $slot }}</a>
