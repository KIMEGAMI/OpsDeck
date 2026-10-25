@props(['id', 'name', 'label', 'autocomplete' => 'new-password', 'minlength' => null, 'toggleLabel' => 'パスワード'])

<label for="{{ $id }}">{{ $label }}</label>
<div class="password-input-row">
    <input id="{{ $id }}" name="{{ $name }}" type="password" autocomplete="{{ $autocomplete }}" required @if ($minlength !== null) minlength="{{ $minlength }}" @endif>
    <button type="button" class="password-toggle" data-password-toggle data-password-toggle-label="{{ $toggleLabel }}" aria-controls="{{ $id }}" aria-label="{{ $toggleLabel }}を表示" hidden>表示</button>
</div>
