<!-- resources/views/emails/account-status-changed.blade.php -->
@component('mail::message')
# Account Status Update

Hello **{{ $name }}**,

Your NEXMART account has been **{{ $status }}**.

@if($status === 'suspended')
Your account has been temporarily suspended. Please contact support for more information.
@elseif($status === 'deactivated')
Your account has been deactivated. If this was a mistake, please contact support.
@else
Your account has been activated. You can now log in and use all features of the platform.
@endif

@component('mail::button', ['url' => config('app.url') . '/login'])
Go to NEXMART
@endcomponent

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent