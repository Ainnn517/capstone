<!doctype html>
<html lang="en">
<body>
    <p>Hello {{ $fullName }},</p>
    <p>Someone (hopefully you) asked to change the password for your Barangay Reporting System account. Enter this code on the Account page to confirm:</p>
    <p style="font-size: 28px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>
    <p>The code expires in {{ $expiresInMinutes }} minutes. Your password stays the same until the code is entered.</p>
    <p>If you did not ask to change your password, ignore this email and sign in to change your password right away, because someone may know your current password.</p>
    <p>Barangay Reporting System</p>
</body>
</html>
