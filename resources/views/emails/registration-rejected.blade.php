<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Status</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7fa; margin: 0; padding: 40px 0; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); padding: 40px; }
        .header { text-align: center; border-bottom: 2px solid #dc2626; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { color: #b91c1c; margin: 0; font-size: 28px; }
        .content { color: #1e293b; line-height: 1.6; }
        .reason-box { background: #fef2f2; border-left: 4px solid #dc2626; padding: 15px 20px; margin: 20px 0; border-radius: 4px; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 14px; color: #64748b; text-align: center; }
        .highlight { color: #dc2626; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Application Status Update</h1>
        </div>
        <div class="content">
            <p>Hello <strong>{{ $name }}</strong>,</p>
            <p>We regret to inform you that your NEXMART account application has been <span class="highlight">rejected</span>.</p>
            
            <div class="reason-box">
                <strong>Reason for rejection:</strong><br>
                {{ $reason }}
            </div>
            
            <p>If you believe this is a mistake or you'd like to reapply, please contact our support team.</p>
            <p>Thanks,<br><strong>NEXMART Team</strong></p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} NEXMART. All rights reserved.</p>
        </div>
    </div>
</body>
</html>