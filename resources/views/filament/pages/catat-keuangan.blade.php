<x-filament-panels::page>
    <div class="lp-q">
        @foreach ($this->cards() as [$name, $title, $help])
            <button type="button" wire:click="mountAction('{{ $name }}')"><b>{{ $title }}</b><span>{{ $help }}</span></button>
        @endforeach
    </div>
    <p class="iz-task-sub" style="margin:0">Biaya rutin seperti listrik dan sewa dicatat di menu "Catat biaya".</p>
</x-filament-panels::page>
