<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #1e293b; padding: 24px;">
  <h2 style="color: #0f766e;">New invitation requested</h2>
  <p><strong>{{ $inviteeEmail }}</strong> tried to join <strong>{{ $companyName }}</strong>, but their invitation had expired.</p>
  <p>You can send a fresh link from the Team page.</p>
  <p style="margin: 28px 0;">
    <a href="{{ $teamUrl }}" style="background: #0f766e; color: #ffffff; padding: 12px 22px; border-radius: 8px; text-decoration: none; font-weight: bold;">Open Team</a>
  </p>
  <p style="margin-top: 32px; color: #64748b; font-size: 13px;">— The BuyTheWay Team</p>
</body>
</html>
