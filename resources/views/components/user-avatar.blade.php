@props(['user' => null])

{{--
    A person's face, in one place.

    WHY THIS EXISTS. The same avatar was written inline four times — twice in
    the site header, once in the admin bar — and the copies had already
    drifted: the admin drew a rounded-lg square while the site drew a circle,
    and the two computed the initials by different rules (the site took the
    first letter of the name, the admin took first-and-last through
    User::initials()). Nobody decided that; it is just what happens to a
    thing written out four times.

    IT SHOWS THE UPLOADED PICTURE, which none of the copies did. `avatar_path`
    is on the users table, it is in the fillable list, and AnonymiseUser
    deletes the file when an account is erased — every part of that column
    existed except the part that put it on the screen. Note that nothing
    writes it yet either: there is no avatar upload in profile settings, so
    today every avatar falls back to initials. That is fine, and it means the
    upload can be added later without touching a single template.

    ROUND, EVERYWHERE. A square avatar is a logo; a round one is a person.
    The panel and the site now agree on which of those a user is.

    Size and text size come from the caller, because Tailwind compiles at
    build time and a class assembled from a variable would never exist in
    the stylesheet:

        <x-user-avatar class="size-8 text-[0.7rem]" />
--}}
@php
    $u = $user ?? auth()->user();

    /*
     * Resolved here rather than on the model because this component is the
     * only thing that displays an avatar, so this is the single definition.
     *
     * Wrapped in rescue(): a misconfigured avatars disk would otherwise
     * throw from inside the header, on every page, and take the whole panel
     * down over a decoration. Falling back to initials degrades; throwing
     * does not.
     */
    $src = null;

    if ($u?->avatar_path) {
        $src = rescue(
            fn () => \Illuminate\Support\Facades\Storage::disk(config('dbelo.storage.avatars', 'public'))->url($u->avatar_path),
            null,
            false,
        );
    }

    $initials = $u?->initials() ?: '?';
@endphp

@if ($src)
    {{-- alt is empty on purpose. The name is always printed next to this or
         announced by the button's own label, and "Photo of Marco" read out
         before "Marco" is the same word twice. --}}
    <img src="{{ $src }}" alt="" {{ $attributes->class(['shrink-0 rounded-full bg-brand/15 object-cover']) }} />
@else
    <span aria-hidden="true"
          {{ $attributes->class(['grid shrink-0 place-items-center rounded-full bg-brand font-semibold text-white']) }}>
        {{ $initials }}
    </span>
@endif
