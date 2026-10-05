<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>NU HRIS Timekeeper credentials</title>
</head>
<body style="margin:0;padding:24px;background:#eceef1;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;margin:auto;background:#fff;border:1px solid #dbe0e6;border-radius:12px;">
        <tr>
            <td style="background:#00386f;padding:24px 28px;color:#fff;">
                <div style="font-size:20px;font-weight:bold;">NU HRIS</div>
                <div style="font-size:13px;color:#c7d7ea;margin-top:2px;">Timekeeper account credentials</div>
            </td>
        </tr>
        <tr>
            <td style="padding:28px;">
                <h1 style="margin:0 0 12px;font-size:22px;color:#1f2b5d;">Welcome to NU HRIS</h1>
                <p style="font-size:14px;line-height:1.55;color:#374151;">
                    Your HR Timekeeper account has been created. Use the credentials below to sign in, then change your temporary password.
                </p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0;background:#f4f7fb;border:1px solid #dbe0e6;border-radius:10px;">
                    <tr>
                        <td style="padding:14px 18px;font-size:12px;color:#6b7280;">Email / Username</td>
                        <td style="padding:14px 18px;font-size:14px;font-family:'Courier New',monospace;text-align:right;">{{ $user->email }}</td>
                    </tr>
                    <tr>
                        <td style="padding:14px 18px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;">Temporary Password</td>
                        <td style="padding:14px 18px;font-size:16px;color:#00386f;font-family:'Courier New',monospace;font-weight:bold;text-align:right;border-top:1px solid #e5e7eb;">{{ $temporaryPassword }}</td>
                    </tr>
                </table>
                <div style="margin:22px 0;text-align:center;">
                    <a href="{{ $loginUrl }}" style="display:inline-block;background:#00386f;color:#fff;text-decoration:none;padding:12px 26px;border-radius:8px;font-size:14px;font-weight:bold;">Sign in to NU HRIS</a>
                </div>
                <p style="font-size:12px;line-height:1.55;color:#6b7280;">If you did not expect this email, please contact your system administrator.</p>
            </td>
        </tr>
    </table>
</body>
</html>
