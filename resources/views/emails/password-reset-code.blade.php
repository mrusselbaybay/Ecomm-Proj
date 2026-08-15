@component('mail::message')
# Password Reset Code

You requested to reset your NEXMART account password.

Your verification code is:

@component('mail::panel')
<h1 style="text-align: center; font-size: 32px; letter-spacing: 8px; color: #ea580c;">
    {{ $code }}
</h1>
@endcomponent

This code will expire in **15 minutes**.

If you didn't request this, you can safely ignore this email.

Thanks,<br>
NEXMART
@endcomponent