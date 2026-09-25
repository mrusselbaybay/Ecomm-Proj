<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #1e293b; padding: 24px;">
  <h2 style="color: #0f766e;">Join {{ $companyName }}</h2>
  <p>{{ $inviterName }} invited you to join <strong>{{ $companyName }}</strong> on BuyTheWay Logistics as <strong>{{ $roleLabel }}</strong>.</p>
  <p style="margin: 28px 0;">
    <a href="{{ $acceptUrl }}" style="background: #0f766e; color: #ffffff; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: bold;">Accept invitation</a>
  </p>
  <p style="color: #64748b; font-size: 13px;">This link expires in {{ $ttlDays }} days. If you weren't expecting this, you can ignore this email.</p>
  <p style="color: #64748b; font-size: 12px; word-break: break-all;">{{ $acceptUrl }}</p>
  <p style="margin-top: 32px; color: #64748b; font-size: 13px;">— The BuyTheWay Team</p>
</body>
</html>
