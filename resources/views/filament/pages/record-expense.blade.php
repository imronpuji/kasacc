<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        <div class="iz-mt-16"><x-filament::button type="submit">Catat biaya</x-filament::button></div>
    </form>
</x-filament-panels::page>
