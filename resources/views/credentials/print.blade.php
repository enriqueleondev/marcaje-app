@php($institution = \App\Models\Setting::institutionName())
<x-layouts.base :title="$title">
    <div class="print-bar">
        <div>
            <h1 class="serif">{{ $title }}</h1>
            <p class="muted">{{ $students->count() === 1 ? '1 credencial' : $students->count().' credenciales' }}. Tamaño tarjeta (85.6 × 54 mm), 8 por hoja carta.</p>
        </div>
        <button type="button" class="btn primary" data-print>Imprimir</button>
    </div>

    <div class="sheet">
        @foreach ($students as $s)
            <article class="card">
                <div class="card-info">
                    <span class="card-org">{{ $institution ?? 'Marcaje' }}</span>
                    <span class="card-name">{{ $s->first_name }}<br>{{ trim($s->last_name_paternal.' '.$s->last_name_maternal) }}</span>
                    <span class="card-meta">{{ $s->group?->name ?? 'Sin grupo' }}</span>
                    <span class="card-meta mono">Matrícula {{ $s->enrollment_number }}</span>
                </div>
                <div class="card-qr">
                    {!! \App\Support\QrImage::svg($s->activeQr->value) !!}
                    <span class="mono">{{ $s->activeQr->display }}</span>
                </div>
            </article>
        @endforeach
    </div>
</x-layouts.base>
