@auth
    @php
        $portals = auth()->user()->accessiblePortals();
        $currentPrefix = request()->segment(1);
    @endphp

    @if (count($portals) > 1)
        <div class="border-b border-slate-200 p-2">
            <p class="px-3 pb-1 pt-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Switch portal</p>
            @foreach ($portals as $portal)
                @php $isCurrent = $currentPrefix === $portal['prefix']; @endphp
                <a href="{{ route($portal['route']) }}"
                   class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium {{ $isCurrent ? 'bg-slate-100 text-[#0a3f79]' : 'text-slate-700 hover:bg-slate-50' }}"
                   @if ($isCurrent) aria-current="page" @endif
                   data-testid="portal-switch-{{ $portal['prefix'] }}">
                    {{ $portal['label'] }}
                    @if ($isCurrent)
                        <span class="text-xs text-slate-400">Current</span>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
@endauth
