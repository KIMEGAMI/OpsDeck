@props(['field', 'id' => null])

@error($field)
    <p id="{{ $id ?? $field.'-error' }}" class="errors field-error">{{ $message }}</p>
@enderror
