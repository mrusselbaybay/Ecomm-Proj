<!-- resources/views/emails/account-status-changed.blade.php -->
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Status Update</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7fa; margin: 0; padding: 40px 0; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); padding: 40px; }
        .header { text-align: center; border-bottom: 2px solid #0f766e; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { color: #0f766e; margin: 0; font-size: 28px; }
        .content { color: #1e293b; line-height: 1.6; }
        .reason-box { 
            background: #f8fafc; 
            border-left: 4px solid #0d9488; 
            padding: 15px 20px; 
            margin: 20px 0; 
            border-radius: 4px; 
        }
        .reason-box .label { font-weight: 600; color: #0d9488; }
        .btn { display: inline-block; background: #0d9488; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; margin-top: 20px; font-weight: 600; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 14px; color: #64748b; text-align: center; }
        .status-active { color: #0d9488; font-weight: 600; }
        .status-suspended { color: #f59e0b; font-weight: 600; }
        .status-deactivated { color: #dc2626; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Account Status Update</h1>
        </div>
        <div class="content">
            <p>Hello <strong>{{ $name }}</strong>,</p>
            <p>Your NEXMART account has been <span class="status-{{ $status }}">{{ $statusLabel }}</span>.</p>
            
            <div class="reason-box">
                <p><span class="label">Reason:</span></p>
                <p>{{ $reason }}</p>
            </div>
            
            @if($status === 'active')
            <p>Your account is now fully active. You can log in and start using all features of the platform.</p>
            <p style="text-align: center;">
                <a href="{{ $appUrl }}/login" class="btn">Login to NEXMART</a>
            </p>
            @elseif($status === 'suspended')
            <p>Your account has been temporarily suspended. Please contact support for more information.</p>
            @elseif($status === 'deactivated')
            <p>Your account has been permanently deactivated. If this was a mistake, please contact support.</p>
            @endif
            
            <p>Thanks,<br><strong>NEXMART Team</strong></p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} NEXMART. All rights reserved.</p>
        </div>
    </div>
</body>
</html>