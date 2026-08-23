<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #1e293b; padding: 24px;">
  <h2 style="color: #0f766e;">You've been invited to interview</h2>
  <p>Hi {{ $courierName }},</p>
  <p><strong>{{ $companyName }}</strong> reviewed your application and would like to move forward with an interview.</p>
  <div style="background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 8px; padding: 16px; margin: 16px 0;">
    <p style="margin: 0; font-size: 13px; color: #0f766e; text-transform: uppercase; letter-spacing: .04em; font-weight: 600;">Interview scheduled for</p>
    <p style="margin: 4px 0 0; font-size: 18px; font-weight: 700;">{{ $interviewAtFormatted }}</p>
  </div>
  @if($notes)
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; margin: 16px 0;">
      <strong>Notes from {{ $companyName }}:</strong>
      <p style="margin: 6px 0 0;">{{ $notes }}</p>
    </div>
  @endif
  <p>Your application status stays <strong>pending</strong> while this is arranged — the company will reach out if anything changes.</p>
  <p style="margin-top: 32px; color: #64748b; font-size: 13px;">— The NEXMART Team</p>
</body>
</html>
