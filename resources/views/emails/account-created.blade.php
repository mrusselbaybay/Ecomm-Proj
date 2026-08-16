<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Created</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7fa; margin: 0; padding: 40px 0; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); padding: 40px; }
        .header { text-align: center; border-bottom: 2px solid #0d9488; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { color: #0f766e; margin: 0; font-size: 28px; }
        .content { color: #1e293b; line-height: 1.6; }
        .credentials { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .credentials code { display: block; padding: 8px 12px; background: white; border-radius: 4px; border: 1px solid #e2e8f0; margin: 8px 0; font-size: 14px; }
        .btn { display: inline-block; background: #0d9488; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; margin-top: 20px; font-weight: 600; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 14px; color: #64748b; text-align: center; }
        .highlight { color: #0d9488; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Your {{ $roleLabel }} Account</h1>
        </div>
        <div class="content">
            <p>Hello <strong>{{ $name }}</strong>,</p>
            <p>A <span class="highlight">{{ $roleLabel }}</span> account has been created for you on NEXMART.</p>
            
            <div class="credentials">
                <p><strong>Your login credentials:</strong></p>
                <p><strong>Email:</strong> <code>{{ $email }}</code></p>
                <p><strong>Password:</strong> <code>{{ $password }}</code></p>
                <p style="font-size: 14px; color: #64748b; margin-top: 8px;">Please change your password after your first login.</p>
            </div>
            
            <p style="text-align: center;">
                <a href="{{ $appUrl }}/login" class="btn">Login to NEXMART</a>
            </p>
            
            <p>Thanks,<br><strong>NEXMART Team</strong></p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} NEXMART. All rights reserved.</p>
        </div>
    </div>
</body>
</html>