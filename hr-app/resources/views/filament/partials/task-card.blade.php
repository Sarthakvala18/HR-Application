{{-- Guided instructions for a manual provisioning step. --}}
<div class="space-y-4 text-sm">
    @if ($app->console_url)
        <div>
            <a href="{{ $app->console_url }}" target="_blank" rel="noopener"
               class="text-primary-600 dark:text-primary-400 underline font-medium">
                Open {{ $app->name }} console
            </a>
        </div>
    @endif

    <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3 space-y-1">
        <div class="font-semibold">Details to enter</div>
        <div><strong>Name:</strong> {{ $employee->full_name }}</div>
        @if ($employee->personal_email)
            <div><strong>Personal email:</strong> {{ $employee->personal_email }}</div>
        @endif
        @if ($employee->work_email)
            <div><strong>Work email:</strong> {{ $employee->work_email }}</div>
        @endif
        @if ($employee->roleTemplate)
            <div><strong>Role template:</strong> {{ $employee->roleTemplate->name }}</div>
        @endif
    </div>

    <div class="prose prose-sm dark:prose-invert max-w-none">
        {!! str($app->onboard_instructions_md)->markdown()->sanitizeHtml() !!}
    </div>
</div>
