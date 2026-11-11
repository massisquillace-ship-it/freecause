@props([
    'name',
    'type' => 'text',
    'label' => null,
    'id' => null,
    'value' => null,
    'required' => false,
    'placeholder' => null,
    'error' => null,
    'hint' => null,
])

@php
    $fieldId = $id ?? $name;
    $errorId = "{$fieldId}-error";
    $hintId = "{$fieldId}-hint";

    $describedBy = [];
    if ($hint && ! $error) {
        $describedBy[] = $hintId;
    }
    if ($error) {
        $describedBy[] = $errorId;
    }
    $describedBy = implode(' ', $describedBy);
@endphp

<div class="fcui-field">
    @if ($label)
        <label for="{{ $fieldId }}" class="fcui-field__label">
            {{ $label }}
            @if ($required)
                <span class="fcui-field__required" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $fieldId }}"
        @if (! is_null($value)) value="{{ $value }}" @endif
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        @required($required)
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge(['class' => 'fcui-input' . ($error ? ' fcui-input--error' : '')]) }}
    >

    @if ($hint && ! $error)
        <p id="{{ $hintId }}" class="fcui-field__hint">{{ $hint }}</p>
    @endif

    @if ($error)
        <p id="{{ $errorId }}" class="fcui-field__error">{{ $error }}</p>
    @endif
</div>
