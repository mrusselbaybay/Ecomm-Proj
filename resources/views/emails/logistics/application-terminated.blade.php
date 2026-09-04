<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #1e293b; padding: 24px;">
  <h2 style="color: #b91c1c;">Engagement ended</h2>
  <p>Hi {{ $courierName }},</p>
  <p><strong>{{ $companyName }}</strong> has ended your engagement as one of their couriers, effective immediately. You have been removed from their delivery areas.</p>
  @if($reason)
    <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; margin: 16px 0;">
      <strong>Reason:</strong> {{ $reason }}
    </div>
  @endif
  <p>Your account remains active. You're free to apply to other logistics companies on NEXMART right away.</p>
  <p style="margin-top: 32px; color: #64748b; font-size: 13px;">— The NEXMART Team</p>
</body>
</html>
