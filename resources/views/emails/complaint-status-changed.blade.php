<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Complaint Update</title>
</head>
<body style="font-family: Arial, sans-serif; color: #334155; line-height: 1.6;">
    <h1 style="color: #0f766e;">Complaint Update</h1>
    <p>Your NEXMART complaint, <strong>{{ $complaintSubject }}</strong>, has been updated.</p>
    <p><strong>Status:</strong> {{ ucwords(str_replace('_', ' ', $status)) }}</p>
    <p><strong>Update:</strong> {{ $notes }}</p>
    @if ($resolution)
        <p><strong>Resolution:</strong> {{ $resolution }}</p>
    @endif
    <p>You may reply through the platform when participant messaging becomes available.</p>
    <p>Thank you,<br>NEXMART Dispute Resolution Team</p>
</body>
</html>
