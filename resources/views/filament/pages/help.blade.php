<x-filament-panels::page>
    <div class="iz-row" style="align-items:flex-start">
        <nav class="iz-card iz-grow-1" aria-label="Daftar bantuan" style="position:sticky;top:16px">
            @foreach ($topics as $t)
                <div class="iz-task" style="padding:10px 0"><a href="#{{ $t['id'] }}" class="iz-link" style="font-size:15px">{{ $t['title'] }}</a></div>
            @endforeach
        </nav>
        <div class="iz-grow-15" style="display:flex;flex-direction:column;gap:16px">
            @foreach ($topics as $t)
                <section id="{{ $t['id'] }}" class="iz-card">
                    <h2 style="margin-bottom:10px">{{ $t['title'] }}</h2>
                    <ol class="iz-steps">
                        @foreach ($t['steps'] as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                    @if ($t['tip'])
                        <div class="iz-info" style="margin-top:14px"><b>Tips:</b> {{ $t['tip'] }}</div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
