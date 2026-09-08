import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',

                /*
                 * Its own entry, NOT part of app.js.
                 *
                 * app.js is loaded on every page of the public site, and
                 * nothing outside /converter needs ffmpeg. Folding it in
                 * would put the wrapper — and its import graph — in front
                 * of every visitor reading a blog post.
                 *
                 * The 30 MB wasm core is not bundled by this entry either:
                 * it is fetched at runtime from public/vendor/ffmpeg, and
                 * only after the visitor picks a file.
                 */
                'resources/js/converter.js',
            ],
            refresh: true,

            /*
             * No fonts declared here any more.
             *
             * It self-hosted Instrument Sans — a leftover from the Laravel
             * starter kit — which nothing in this project ever used: the
             * @theme block in resources/css/app.css sets --font-sans to
             * Outfit and --font-display to Playfair Display, and both come
             * from the @import at the top of that file. Every build was
             * downloading and shipping about 120KB of woff2 that no element
             * on the site was ever styled with.
             *
             * The `@fonts` directive that emitted its stylesheet came out of
             * partials/head.blade.php in the same commit.
             *
             * WORTH DOING NEXT, and deliberately not done here: moving
             * Outfit and Playfair off that Google Fonts @import and into
             * this array through bunny(). It would drop a render-blocking
             * third-party request and stop handing Google the IP address of
             * every visitor — which matters more than usual on a site whose
             * privacy policy is one of its own pages.
             */
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
