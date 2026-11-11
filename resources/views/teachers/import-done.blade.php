<x-layouts.app title="Cuentas creadas">
    <div class="page-head">
        <div>
            <h1>{{ count($done['accounts']) === 1 ? '1 cuenta creada' : count($done['accounts']).' cuentas creadas' }}</h1>
            <p>Entrega a cada persona su contraseña temporal. Vence en 24 horas y al entrar tendrá que elegir una propia. <strong>Esta lista no se volverá a mostrar.</strong></p>
        </div>
        <a class="btn" href="{{ route('teachers.index') }}">Ir a docentes y dirección</a>
    </div>
    <div class="table-wrap block">
        <table class="table">
            <thead><tr><th>Nombre</th><th>Correo</th><th>Contraseña temporal</th></tr></thead>
            <tbody>
                @foreach ($done['accounts'] as $a)
                    <tr><td class="serif cell-name">{{ $a['name'] }}</td><td class="mono">{{ $a['email'] }}</td><td class="mono">{{ $a['password'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mono" id="all-passwords" hidden>{{ collect($done['accounts'])->map(fn ($a) => $a['email'].'  '.$a['password'])->join("\n") }}</p>
    <p style="margin-top:16px"><button type="button" class="btn" data-copy="#all-passwords">Copiar todo</button></p>
</x-layouts.app>
