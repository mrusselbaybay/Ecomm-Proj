@component('mail::message')
# Reset your password

We received a request to reset your NEXMART account password.

@component('mail::button', ['url' => $actionLink])
Reset Password
@endcomponent

If you didn't request this, you can safely ignore this email — your password won't change.

Thanks,<br>
NEXMART
@endcomponent