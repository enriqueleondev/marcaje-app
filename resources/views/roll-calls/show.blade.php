<x-layouts.app :title="'Pase de lista: '.$rollCall->course->name" flush>
    <livewire:roll-call-sheet :roll-call="$rollCall" />
</x-layouts.app>
