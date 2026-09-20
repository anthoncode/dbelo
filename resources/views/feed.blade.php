{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
{{--
    ── WHY THE DECLARATION IS SPLIT ACROSS CONCATENATIONS ───────────────

    The same reason it is split in sitemap.blade.php, and this file is the
    proof that writing it down once was not enough: it was written the
    obvious way, and it was a 500 from the day it was created — nobody had
    opened /blog/feed until the footer grew a link to it.

    This PHP build has short_open_tag ON. Blade does not compile a template
    as text: compileString() runs the source through token_get_all() and
    only transforms the chunks PHP's tokeniser reports as inline HTML.
    Anything it considers code is copied through untouched. With short tags
    enabled, an angle bracket followed immediately by a question mark
    ANYWHERE in the file opens PHP as far as that tokeniser is concerned —
    including inside what was meant to be a quoted string.

    So the literal version tokenises into three pieces: the opening braces,
    then "xml version…" as PHP code, then the closing braces. The echo is
    split across two chunks, Blade never recognises it, and the line is
    copied into the compiled file verbatim. PHP then executes THAT, meets
    the bare word `version`, and dies with:

        syntax error, unexpected identifier "version"

    Splitting the string keeps those two characters from ever being
    adjacent in the source. The tokeniser sees one continuous run of HTML,
    Blade compiles the echo normally, and the declaration is reassembled at
    runtime — long after any parser has looked at it. The closing pair is
    split for the same reason.

    THE REAL FIX IS short_open_tag = Off, which is the default everywhere
    and has been discouraged for fifteen years precisely because of XML.
    Until that is changed on this machine, every XML template in the
    project has to be written like this — and the ones that are not will
    not fail until somebody visits them.
--}}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ config('app.name', 'dbelo') }} — Blog</title>
        <link>{{ route('blog') }}</link>
        <description>Guides, techniques and news about sound design, field recording and working with audio.</description>
        <language>en</language>
        <lastBuildDate>{{ $updated }}</lastBuildDate>
        <atom:link href="{{ route('blog.feed') }}" rel="self" type="application/rss+xml" />

        @foreach ($items as $item)
            <item>
                <title>{{ $item['title'] }}</title>
                <link>{{ $item['link'] }}</link>
                <guid isPermaLink="true">{{ $item['link'] }}</guid>
                <description>{{ $item['description'] }}</description>
                <pubDate>{{ $item['pubDate'] }}</pubDate>
            </item>
        @endforeach
    </channel>
</rss>
