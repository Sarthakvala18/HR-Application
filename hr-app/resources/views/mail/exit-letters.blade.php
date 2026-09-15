@php
    $plural = count($letters) > 1;
@endphp

<x-mail::message>
# Dear {{ $name }},

Thank you for your time with Coach Foundation. Please find {{ $plural ? 'your letters' : 'your letter' }} attached to this email:

<x-mail::panel>
@foreach ($letters as $letter)
- {{ $letter['title'] }}
@endforeach
</x-mail::panel>

Please review the attached {{ $plural ? 'documents' : 'document' }}, sign where indicated, and keep a copy for your records.

If anything looks incorrect, reply to this email or contact us at {{ $hrEmail }} and we will put it right.

We wish you all the best for what comes next.

Thanks,<br>
HR Department<br>
Coach LLC
</x-mail::message>
