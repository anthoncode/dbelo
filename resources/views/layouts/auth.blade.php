{{--
    The auth layout, chosen in exactly one place.

    Every signed-out screen — log in, sign up, forgot password, reset
    password, confirm password, verify email, two-factor challenge — renders
    through this file. It exists so that choosing a different auth layout is
    a one-line change here rather than seven edits that will be six.

    It used to point at auth.simple, the starter kit's centred card, while
    login and register had been moved to auth.split by hand. That is how the
    five remaining screens ended up looking like a different product: nobody
    had changed them, and nothing said they needed changing.

    The alternatives are still there — auth.simple and auth.card — and
    swapping is a word.
--}}
<x-layouts::auth.split :title="$title ?? null">
    {{ $slot }}
</x-layouts::auth.split>
