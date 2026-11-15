<!doctype html>
<html lang="en">
<body style="font-family: Arial, sans-serif; color: #1f2a24; line-height: 1.5;">
    <p>Hello {{ $fullName }},</p>
    <p><strong>{{ $headline }}</strong></p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 12px 0; border-collapse: collapse;">
        <tr><td style="padding: 4px 16px 4px 0; color: #5b6660;">Tracking code</td><td style="padding: 4px 0;"><strong>{{ $tracking }}</strong></td></tr>
        <tr><td style="padding: 4px 16px 4px 0; color: #5b6660;">Status</td><td style="padding: 4px 0;"><strong>{{ $status }}</strong></td></tr>
    </table>
    <p style="margin: 0 0 4px; color: #5b6660;">Message from the barangay:</p>
    <p style="margin: 0 0 16px; padding: 10px 14px; background: #f1f5f2; border-left: 3px solid #54745f;">{{ $adminMessage }}</p>
    @if ($respondBy)
        <p><strong>Is the problem fixed?</strong> Please open your report and choose "Yes, it's fixed" or "No, still a problem" by {{ $respondBy }}. If you don't answer, the report closes as fixed.</p>
    @endif
    <p><a href="{{ $reportUrl }}" style="display: inline-block; padding: 10px 18px; background: #54745f; color: #ffffff; text-decoration: none; border-radius: 8px;">Open my reports</a></p>
    <p>Barangay Reporting System</p>
    <p style="font-size: 12px; color: #5b6660;">You get these emails because you reported an issue. <a href="{{ $unsubscribeUrl }}" style="color: #5b6660;">Stop emails about my reports</a></p>
</body>
</html>
