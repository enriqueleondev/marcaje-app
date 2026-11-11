@props(['name', 'label', 'value' => null, 'type' => 'text', 'hint' => null])
@php($id = $attributes->get('id', $name))
<div class="field">
    <label for="{{ $id }}">{{ $label }}</label>
    <input {{ $attributes->except(['id', 'class'])->merge(['class' => trim('input '.$attributes->get('class'))]) }}
           id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}"
           @if ($hint || $errors->has($name)) aria-describedby="{{ $hint ? $id.'-hint' : '' }} {{ $errors->has($name) ? $id.'-error' : '' }}" @endif
           @error($name) aria-invalid="true" @enderror>
    @if ($hint)<p class="hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
    @error($name)<p class="error" id="{{ $id }}-error">{{ $message }}</p>@enderror
</div>
