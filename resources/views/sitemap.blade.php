{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
{{--
    ── WHY THE DECLARATION IS SPLIT ACROSS CONCATENATIONS ───────────────

    Because this PHP build has short_open_tag ON, and that breaks Blade in
    a way that is very hard to see.

    Blade does not compile a template as text. compileString() runs the
    source through token_get_all() and only transforms the chunks PHP's
    tokeniser reports as inline HTML; anything it considers code is passed
    through untouched. With short tags enabled, an angle bracket followed
    immediately by a question mark ANYWHERE in the file opens PHP as far as
    that tokeniser is concerned — including inside what was meant to be a
    quoted string.

    So writing the declaration literally, even inside a raw echo, tokenises
    into three pieces: the opening braces, then "xml version…" as PHP code,
    then the closing braces. The echo is split across two chunks, Blade
    never recognises it, and the whole line is copied into the compiled
    file verbatim. PHP then executes THAT, meets the bare word `version`,
    and dies with:

        syntax error, unexpected identifier "version"

    Splitting the string keeps those two characters from ever being
    adjacent in the source. The tokeniser sees one continuous run of HTML,
    Blade compiles the echo normally, and the declaration is reassembled at
    runtime — long after any parser has looked at it. The closing pair is
    split for the same reason.

    NOTE FOR WHOEVER EDITS THIS FILE: the same trap applies to comments.
    This block deliberately describes those two characters in words rather
    than printing them, because the tokeniser reads the raw file before
    Blade strips comments — an example inside this very comment would
    reopen the bug it explains.

    ── THE DURABLE FIX IS IN php.ini ────────────────────────────────────

    short_open_tag has been discouraged for years and is off by default.
    Turning it off in Herd removes this entire class of problem. This file
    works either way.

    ── AND WHY THIS COMMENT IS ON LINE 2 ────────────────────────────────

    An XML declaration must be the very first thing in the document — not
    one space, not one blank line. A Blade comment compiles away to nothing
    but leaves its newline, so putting this above the declaration would
    push it down and every parser would reject the file.
--}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($urls as $url)
    <url>
        <loc>{{ $url['loc'] }}</loc>
@isset($url['lastmod'])
        <lastmod>{{ $url['lastmod'] }}</lastmod>
@endisset
        <changefreq>{{ $url['changefreq'] }}</changefreq>
        <priority>{{ $url['priority'] }}</priority>
    </url>
@endforeach
</urlset>
