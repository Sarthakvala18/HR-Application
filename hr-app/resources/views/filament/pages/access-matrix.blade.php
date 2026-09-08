<x-filament-panels::page>
    @php
        $apps = $this->apps();
        $employees = $this->employees();
        $risk = $this->riskCount();
        $wasted = $this->wastedSeatCount();
    @endphp

    {{-- Headline numbers: the two that cost money or create exposure. --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div @class([
            'rounded-xl border p-4',
            'border-danger-300 bg-danger-50 dark:border-danger-800 dark:bg-danger-950/40' => $risk > 0,
            'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => $risk === 0,
        ])>
            <div class="text-sm text-gray-500 dark:text-gray-400">Exited, still has access</div>
            <div @class([
                'mt-1 text-3xl font-bold',
                'text-danger-600 dark:text-danger-400' => $risk > 0,
                'text-gray-950 dark:text-white' => $risk === 0,
            ])>{{ $risk }}</div>
            @if ($risk > 0)
                <button wire:click="$set('scope', 'risk')"
                        class="mt-2 text-xs font-medium text-danger-700 underline dark:text-danger-400">
                    Show them
                </button>
            @else
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Nothing outstanding.</div>
            @endif
        </div>

        <div @class([
            'rounded-xl border p-4',
            'border-warning-300 bg-warning-50 dark:border-warning-800 dark:bg-warning-950/40' => $wasted > 0,
            'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => $wasted === 0,
        ])>
            <div class="text-sm text-gray-500 dark:text-gray-400">Paid seats held by leavers</div>
            <div @class([
                'mt-1 text-3xl font-bold',
                'text-warning-600 dark:text-warning-400' => $wasted > 0,
                'text-gray-950 dark:text-white' => $wasted === 0,
            ])>{{ $wasted }}</div>
            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Billed again at the next cycle.</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
            <div class="text-sm text-gray-500 dark:text-gray-400">Paid seats in use</div>
            <div class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ $this->billableSeatCount() }}</div>
            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">Across every licensed app.</div>
        </div>
    </div>

    {{-- Controls --}}
    <div class="flex flex-wrap items-center gap-3">
        <select wire:model.live="scope"
                class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
            @foreach ($this->scopeOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search people"
               class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5">

        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $employees->count() }} shown</span>
    </div>

    {{-- The grid. Wide by nature, so it scrolls inside its own container. --}}
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-white/10">
                    <th class="sticky left-0 z-10 bg-white px-4 py-3 text-left font-semibold dark:bg-gray-900">
                        Person
                    </th>
                    @foreach ($apps as $app)
                        <th class="px-2 py-3 text-center font-medium whitespace-nowrap">
                            <div class="text-xs">{{ $app->name }}</div>
                            <div class="mt-0.5 text-[10px] font-normal text-gray-400">
                                {{ $app->provisioning_mode->value === 'manual' ? 'manual' : '' }}
                            </div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $employee)
                    @php $hasLeft = $employee->status->value === 'exited'; @endphp
                    <tr class="border-b border-gray-100 last:border-0 hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5">
                        <td class="sticky left-0 z-10 bg-white px-4 py-2 dark:bg-gray-900">
                            <a href="{{ \App\Filament\Resources\Employees\EmployeeResource::getUrl('view', ['record' => $employee]) }}"
                               class="font-medium text-gray-950 hover:underline dark:text-white">
                                {{ $employee->displayName() }}
                            </a>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $employee->department?->name ?? 'No department' }}
                                @if ($hasLeft)
                                    <span class="ml-1 rounded bg-danger-100 px-1 text-danger-700 dark:bg-danger-900/50 dark:text-danger-300">exited</span>
                                @endif
                            </div>
                        </td>

                        @foreach ($apps as $app)
                            @php
                                $status = $this->cellStatus($employee, $app);
                                $tier = $this->cellTier($employee, $app);
                                $isLive = $status?->isLive() ?? false;
                                $isRisk = $isLive && $hasLeft;
                            @endphp
                            <td class="px-2 py-2 text-center">
                                @if ($status === null)
                                    <span class="text-gray-300 dark:text-gray-600">·</span>
                                @else
                                    <span @class([
                                            'inline-block rounded px-1.5 py-0.5 text-[11px] font-medium',
                                            'bg-danger-100 text-danger-800 ring-1 ring-danger-400 dark:bg-danger-900/60 dark:text-danger-200' => $isRisk,
                                            'bg-success-100 text-success-800 dark:bg-success-900/50 dark:text-success-200' => $isLive && ! $isRisk,
                                            'bg-warning-100 text-warning-800 dark:bg-warning-900/50 dark:text-warning-200' => $status->value === 'pending_manual',
                                            'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $isLive && $status->value !== 'pending_manual',
                                        ])
                                        title="{{ $status->label() }}{{ $tier ? ' — '.$tier : '' }}">
                                        {{ $tier ?: $status->label() }}
                                    </span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $apps->count() + 1 }}" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                            No people match this view.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Cells show the licence tier where one applies. Open a person to grant or revoke individual access.
    </p>
</x-filament-panels::page>
