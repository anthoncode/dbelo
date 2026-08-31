{{--
    The email itself.

    Everything is inline styles and tables on purpose: Outlook still ignores
    <style> blocks, Gmail strips classes, and dark mode inverts what it
    likes. This is not the place for the design system — it is the place for
    what survives twenty different clients.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $campaign->subject }}</title>
</head>
<body style="margin:0; padding:0; background:#f5f4fa; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">

{{-- The grey line the inbox shows next to the subject. Hidden in the body
     itself, which is why it carries the invisible padding after it. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">
    {{ $campaign->preheader }}
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f4fa; padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:20px; overflow:hidden;">

                {{-- Header --}}
                <tr>
                    <td style="background:#1f1d33; padding:24px 32px;">
                        <a href="{{ route('home') }}" style="color:#f5f4fa; font-size:22px; font-weight:bold; text-decoration:none; letter-spacing:-0.04em;">dbelo</a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:32px;">

                        @if ($digest)
                            {{-- ══════ THE WEEKLY DIGEST ══════ --}}
                            <h1 style="margin:0 0 8px; font-size:24px; line-height:1.25; color:#1f1d33; letter-spacing:-0.02em;">
                                New in the catalogue
                            </h1>
                            <p style="margin:0 0 28px; font-size:15px; line-height:1.6; color:#1f1d33; opacity:0.6;">
                                {{ $campaign->preheader }}
                            </p>

                            @foreach ($digest['sounds'] as $sound)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;">
                                    <tr>
                                        <td style="background:#f5f4fa; border-radius:14px; padding:14px 18px;">
                                            <a href="{{ route('sounds.show', $sound) }}"
                                               style="color:#1f1d33; font-size:15px; font-weight:500; text-decoration:none;">
                                                {{ $sound->title }}
                                            </a>
                                            <div style="margin-top:3px; font-size:12px; color:#1f1d33; opacity:0.45;">
                                                {{ $sound->category?->name ?? 'Uncategorised' }} · {{ $sound->durationForHumans() }}
                                                @if (! $sound->is_premium) · free @endif
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            @endforeach

                            @if ($digest['sounds']->isNotEmpty())
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px;">
                                    <tr>
                                        <td style="background:#a32eb7; border-radius:999px;">
                                            <a href="{{ route('sounds.index') }}"
                                               style="display:inline-block; padding:13px 28px; color:#ffffff; font-size:15px; font-weight:500; text-decoration:none;">
                                                Browse the catalogue
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @foreach ($digest['packs'] as $pack)
                                <div style="margin-top:28px; padding-top:24px; border-top:1px solid rgba(31,29,51,0.08);">
                                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.14em; color:#a32eb7;">New pack</div>
                                    <a href="{{ route('collections.show', $pack) }}"
                                       style="display:block; margin-top:8px; color:#1f1d33; font-size:18px; font-weight:500; text-decoration:none;">
                                        {{ $pack->name }}
                                    </a>
                                    @if ($pack->description)
                                        <p style="margin:6px 0 0; font-size:14px; line-height:1.6; color:#1f1d33; opacity:0.6;">{{ $pack->description }}</p>
                                    @endif
                                </div>
                            @endforeach

                            @if ($digest['post'])
                                <div style="margin-top:28px; padding-top:24px; border-top:1px solid rgba(31,29,51,0.08);">
                                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.14em; color:#a32eb7;">From the blog</div>
                                    <a href="{{ $digest['post']->url() }}"
                                       style="display:block; margin-top:8px; color:#1f1d33; font-size:18px; font-weight:500; text-decoration:none;">
                                        {{ $digest['post']->title }}
                                    </a>
                                    <p style="margin:6px 0 0; font-size:14px; line-height:1.6; color:#1f1d33; opacity:0.6;">
                                        {{ $digest['post']->summary(140) }}
                                    </p>
                                </div>
                            @endif
                        @else
                            {{-- ══════ A PROMOTION ══════ --}}
                            <div style="font-size:15px; line-height:1.7; color:#1f1d33;">
                                {!! $campaign->html() !!}
                            </div>
                        @endif

                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:24px 32px 28px; border-top:1px solid rgba(31,29,51,0.08);">
                        <p style="margin:0 0 10px; font-size:12px; line-height:1.6; color:#1f1d33; opacity:0.45;">
                            You are getting this because you have a dbelo account.
                        </p>

                        <p style="margin:0; font-size:12px; line-height:1.8; color:#1f1d33; opacity:0.45;">
                            <a href="{{ $subscriber->unsubscribeUrl() }}" style="color:#1f1d33; opacity:0.7;">Unsubscribe</a>
                            &nbsp;·&nbsp;
                            <a href="{{ $subscriber->unsubscribeUrl() }}" style="color:#1f1d33; opacity:0.7;">Email preferences</a>
                            &nbsp;·&nbsp;
                            <a href="{{ route('legal.privacy') }}" style="color:#1f1d33; opacity:0.7;">Privacy</a>
                        </p>
                    </td>
                </tr>
            </table>

            <p style="margin:20px 0 0; font-size:11px; color:#1f1d33; opacity:0.35;">
                {{ config('dbelo.legal.entity') }}
            </p>
        </td>
    </tr>
</table>

</body>
</html>
