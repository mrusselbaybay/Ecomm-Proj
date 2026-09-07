@component('mail::message')
# Verify Your Email

Welcome to BuyTheWay! Use the code below to verify your email address and continue creating your account.

@component('mail::panel')
<h1 style="text-align: center; font-size: 32px; letter-spacing: 8px; color: #ea580c;">
    {{ $code }}
</h1>
@endcomponent

This code will expire in **15 minutes**.

If you didn't try to create a BuyTheWay account, you can safely ignore this email.

Thanks,<br>
BuyTheWay
@endcomponent
