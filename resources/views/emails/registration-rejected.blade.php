@component('mail::message')
# Update on your application

Hi {{ $profile->first_name }}, after reviewing your NEXMART **{{ ucfirst($profile->role) }}** application, we're unable to approve it at this time.

@component('mail::panel')
**Reason:** {{ $reason }}
@endcomponent

You're welcome to update your information and documents and resubmit your application.

Thanks,<br>
{{ config('app.name') }}
@endcomponent