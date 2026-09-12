{{--
    One layout for every alert, and it is deliberately plain.

    Written by hand rather than with the framework's markdown mail
    components, for one reason: those need their views published, and a
    missing published view turns an alert about a broken backup into a
    second broken thing at exactly the wrong moment.

    Inline styles throughout, because email clients strip <style> blocks —
    Gmail's web client removes external stylesheets outright, and a table
    layout with inline attributes is the only thing that renders the same in
    Outlook, Apple Mail and Gmail.

    No images and no tracking pixel. This goes to one person who already
    knows who sent it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f1f6;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1f6; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:520px; background:#ffffff; border-radius:14px; overflow:hidden; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

                    <tr>
                        <td style="background:#8a43fd; padding:18px 28px; color:#ffffff; font-size:13px; letter-spacing:.16em; text-transform:uppercase;">
                            {{ config('app.name', 'dbelo') }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px;">
                            <h1 style="margin:0 0 14px; font-size:20px; font-weight:600; color:#0d0b10; line-height:1.3;">
                                {{ $heading }}
                            </h1>

                            {{-- nl2br rather than a paragraph loop: the body is
                                 written as plain text in Alerts, so what the
                                 author typed is what arrives. --}}
                            <p style="margin:0 0 22px; font-size:15px; line-height:1.65; color:#4a4552;">
                                {!! nl2br(e($body)) !!}
                            </p>

                            <a href="{{ $url }}"
                               style="display:inline-block; background:#f9510f; color:#ffffff; text-decoration:none; padding:11px 22px; border-radius:999px; font-size:14px; font-weight:500;">
                                {{ $action }}
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 28px 26px; font-size:12px; line-height:1.6; color:#8d8795;">
                            You are getting this because your address is set as the admin email in
                            Settings&nbsp;→&nbsp;General. Alerts can be switched off in Settings&nbsp;→&nbsp;Email.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
