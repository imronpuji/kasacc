<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        <div style="margin-top:16px"><x-filament::button type="submit">Simpan pengaturan pajak</x-filament::button></div>
    </form>
</x-filament-panels::page>
