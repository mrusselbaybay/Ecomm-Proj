<!-- resources/views/emails/registration-approved.blade.php -->
@component('mail::message')
# Welcome to NEXMART!

Hello **{{ $name }}**,

We're excited to inform you that your NEXMART account has been **approved**! 🎉

You can now log in to your account and start using our platform.

@component('mail::button', ['url' => config('app.url') . '/login'])
Login to Your Account
@endcomponent

If you have any questions, feel free to contact our support team.

Thanks,<br>
{{ config('app.name') }} Team
@endcomponent