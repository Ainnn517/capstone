<!doctype html>
<html lang="en">
<body>
    <p>Hello {{ $fullName }},</p>
    <p>Enter this code on the sign-in page to finish signing in to the Barangay Reporting System:</p>
    <p style="font-size: 28px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>
    <p>The code expires in {{ $expiresInMinutes }} minutes.</p>
    <p>If you did not just try to sign in, someone else knows your password. Change it right away and tell the system maintainer.</p>
    <p>Barangay Reporting System</p>
</body>
</html>
