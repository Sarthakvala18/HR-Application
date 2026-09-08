{{-- Guided card for one pipeline step. --}}
<div class="space-y-4 text-sm">
    @if ($task->status->value === 'blocked')
        <div class="rounded-lg bg-warning-50 p-3 text-warning-800 dark:bg-warning-950/40 dark:text-warning-200">
            Waiting on: {{ implode(', ', $task->depends_on ?? []) }}
        </div>
    @endif

    @if ($task->app?->console_url)
        <a href="{{ $task->app->console_url }}" target="_blank" rel="noopener"
           class="inline-block font-medium text-primary-600 underline dark:text-primary-400">
            Open {{ $task->app->name }} console
        </a>
    @endif

    @if ($employee)
        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
            <div class="mb-1 font-semibold">Details to use</div>
            <div><strong>Name:</strong> {{ $employee->full_name }}</div>
            @if ($employee->personal_email)
                <div><strong>Personal email:</strong> {{ $employee->personal_email }}</div>
            @endif
            @if ($employee->work_email)
                <div><strong>Work email:</strong> {{ $employee->work_email }}</div>
            @endif
            @if ($tier = data_get($task->payload, 'license_tier'))
                <div><strong>Licence tier:</strong> {{ $tier }}</div>
            @endif
            @if ($scopes = data_get($task->payload, 'scopes'))
                <div><strong>Scopes:</strong>
                    {{ collect($scopes)->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode('/', $v) : $v))->implode(' · ') }}
                </div>
            @endif
            @if ($destination = data_get($task->payload, 'destination'))
                <div><strong>Destination:</strong> {{ $destination }}</div>
            @endif
        </div>
    @endif

    <div class="prose prose-sm dark:prose-invert max-w-none">
        {!! str($task->description_md)->markdown()->sanitizeHtml() !!}
    </div>

    @if ($task->evidence)
        <div class="rounded-lg bg-success-50 p-3 text-success-800 dark:bg-success-950/40 dark:text-success-200">
            <strong>Recorded:</strong> {{ $task->evidence }}
        </div>
    @endif
</div>
