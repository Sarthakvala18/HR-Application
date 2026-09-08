{{-- Rendered only after an authorised, audited reveal. --}}
<div class="space-y-1 text-sm">
    <div><strong>Account:</strong> {{ $detail->account_number ?: '—' }}</div>
    @if ($detail->iban)
        <div><strong>IBAN:</strong> {{ $detail->iban }}</div>
    @endif
    @if ($detail->swift_code)
        <div><strong>SWIFT:</strong> {{ $detail->swift_code }}</div>
    @endif
    @if ($detail->routing_number)
        <div><strong>Routing:</strong> {{ $detail->routing_number }}</div>
    @endif
    <div class="pt-1 opacity-75">This access has been logged.</div>
</div>
