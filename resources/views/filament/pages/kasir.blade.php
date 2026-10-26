<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2">
        <div class="space-y-3">
            <label class="block text-sm font-medium">Cabang
                <select wire:model.live="branchId" class="block w-full rounded-lg border-gray-300 dark:bg-gray-800">
                    @foreach ($this->branches() as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                </select>
            </label>
            <input wire:model.live.debounce.250ms="search" type="text" autofocus placeholder="Scan barcode / cari SKU atau nama…"
                   class="block w-full rounded-lg border-gray-300 dark:bg-gray-800" />
            <div class="space-y-1">
                @foreach ($this->results as $p)
                    <button wire:click="add({{ $p->id }})" type="button" class="flex w-full justify-between rounded-lg border p-2 text-left hover:bg-gray-50 dark:hover:bg-gray-800">
                        <span>{{ $p->name }} <span class="text-xs text-gray-500">{{ $p->sku }}</span></span>
                        <span>Rp {{ number_format($p->sale_price, 0, ',', '.') }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <div class="space-y-3">
            <table class="w-full text-sm">
                <thead><tr class="text-left"><th>Barang</th><th class="w-20">Qty</th><th class="text-right">Subtotal</th><th></th></tr></thead>
                <tbody>
                @forelse ($cart as $id => $item)
                    <tr wire:key="c{{ $id }}">
                        <td>{{ $item['name'] }}<div class="text-xs text-gray-500">Rp {{ number_format($item['price'], 0, ',', '.') }}</div></td>
                        <td><input type="number" min="1" wire:model.live="cart.{{ $id }}.qty" class="w-16 rounded border-gray-300 dark:bg-gray-800" /></td>
                        <td class="text-right">{{ number_format($item['qty'] * $item['price'], 0, ',', '.') }}</td>
                        <td><button wire:click="remove({{ $id }})" type="button" class="text-red-600">×</button></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-4 text-center text-gray-500">Keranjang kosong</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="flex items-center justify-between text-xl font-semibold"><span>Total</span><span>Rp {{ number_format($this->total, 0, ',', '.') }}</span></div>
            <div class="flex gap-2">
                @foreach (['kas' => 'Tunai', 'bank' => 'Transfer', 'qris' => 'QRIS'] as $k => $label)
                    <label class="flex-1 cursor-pointer rounded-lg border p-2 text-center {{ $method === $k ? 'border-primary-600 bg-primary-50 dark:bg-gray-800' : '' }}">
                        <input type="radio" class="sr-only" wire:model.live="method" value="{{ $k }}" />{{ $label }}
                    </label>
                @endforeach
            </div>
            <x-filament::button wire:click="checkout" :disabled="empty($cart)" class="w-full">Bayar</x-filament::button>
            @if ($lastReceipt)
                <a href="{{ route('struk', $lastReceipt) }}" target="_blank" class="block text-center text-sm underline">Cetak struk {{ $lastReceipt }}</a>
            @endif
        </div>
    </div>
</x-filament-panels::page>
