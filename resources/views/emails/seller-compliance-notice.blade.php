<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seller Compliance Notice</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <h1 style="color: #0f766e;">Seller Compliance Notice</h1>

    <p>Hello {{ $sellerName }},</p>

    <p>
        An administrator reviewed your product
        <strong>{{ $productName }}</strong> and took the following action:
        <strong>{{ ucfirst($action) }}</strong>.
    </p>

    @if ($reason)
        <p><strong>Reason:</strong> {{ $reason }}</p>
    @endif

    @if ($action === 'warn')
        <p>Please correct the issue and ensure future listings follow NEXMART policies.</p>
    @elseif ($action === 'remove')
        <p>The product has been made inactive and is no longer available to buyers.</p>
    @elseif ($action === 'suspend')
        <p>Your seller account has been suspended. Contact platform support before attempting further activity.</p>
    @endif

    <p>Thank you,<br>NEXMART Compliance Team</p>
</body>
</html>
