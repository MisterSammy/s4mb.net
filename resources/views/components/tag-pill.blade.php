@props([
    'slug',
    'label',
    'icon' => null,
    'count' => null,
    'color' => null,
    'active' => false,
    'clickable' => true,
    'small' => false,
])

@php
    $tagRegistry = app(\App\Services\TagRegistryService::class);
    $iconSvg = $icon ?? $tagRegistry->getTagIcon($slug);
@endphp

<button
    type="button"
    data-tag="{{ $slug }}"
    @class([
        'tag-pill',
        'tag-pill--active' => $active,
        'tag-pill--small' => $small,
        'cursor-pointer' => $clickable,
        'cursor-default' => !$clickable,
    ])
    @if($clickable)
        onclick="window.TagFilter?.toggle('{{ $slug }}')"
    @endif
    @if($color)
        style="--tag-accent-color: {{ $color }}"
    @endif
>
    <span class="tag-pill__icon" aria-hidden="true">
        {!! $iconSvg !!}
    </span>
    <span class="tag-pill__label">{{ $label }}</span>
    @if($count !== null)
        <span class="tag-pill__count">{{ $count }}</span>
    @endif
</button>
