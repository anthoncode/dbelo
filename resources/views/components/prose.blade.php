{{--
    The reading styles for anything written in markdown: post bodies, page
    bodies, and the live preview inside the editor.

    One place, so the preview cannot drift from the published page.
--}}
<div {{ $attributes->merge(['class' => '
    leading-relaxed text-ink/75 dark:text-paper/75
    [&>*:first-child]:mt-0
    [&_h2]:mb-3 [&_h2]:mt-10 [&_h2]:text-2xl [&_h2]:font-semibold [&_h2]:tracking-[-0.02em] [&_h2]:text-ink dark:[&_h2]:text-paper
    [&_h3]:mb-2 [&_h3]:mt-8 [&_h3]:text-lg [&_h3]:font-medium [&_h3]:text-ink dark:[&_h3]:text-paper
    [&_h4]:mb-2 [&_h4]:mt-6 [&_h4]:font-medium [&_h4]:text-ink dark:[&_h4]:text-paper
    [&_p]:mb-5
    [&_ul]:mb-5 [&_ul]:ml-5 [&_ul]:list-disc
    [&_ol]:mb-5 [&_ol]:ml-5 [&_ol]:list-decimal
    [&_li]:mb-2
    [&_a]:text-brand [&_a]:underline [&_a]:underline-offset-2 hover:[&_a]:no-underline
    [&_strong]:font-medium [&_strong]:text-ink dark:[&_strong]:text-paper
    [&_blockquote]:my-6 [&_blockquote]:border-l-2 [&_blockquote]:border-brand [&_blockquote]:pl-5 [&_blockquote]:italic
    [&_img]:my-7 [&_img]:w-full [&_img]:rounded-card [&_img]:shadow-soft-md
    [&_hr]:my-10 [&_hr]:border-ink/10 dark:[&_hr]:border-paper/10
    [&_code]:rounded [&_code]:bg-ink/[0.06] [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:text-[0.88em] dark:[&_code]:bg-paper/10
    [&_pre]:my-6 [&_pre]:overflow-x-auto [&_pre]:rounded-card [&_pre]:bg-ink [&_pre]:p-5 [&_pre]:text-[0.85rem] [&_pre]:text-paper
    [&_pre_code]:bg-transparent [&_pre_code]:p-0
    [&_table]:my-6 [&_table]:w-full [&_table]:text-left
    [&_th]:border-b [&_th]:border-ink/10 [&_th]:pb-2 [&_th]:font-medium dark:[&_th]:border-paper/10
    [&_td]:border-b [&_td]:border-ink/5 [&_td]:py-2.5 dark:[&_td]:border-paper/5
']) }}>{{ $slot }}</div>
