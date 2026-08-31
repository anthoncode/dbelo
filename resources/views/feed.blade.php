{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
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
