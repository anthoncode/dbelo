<?php

namespace App\Http\Controllers;

use App\Models\License;
use Illuminate\View\View;

/**
 * The four legal pages.
 *
 * ── THEY USED TO SHARE THE HOMEPAGE'S TITLE ──────────────────────────────
 *
 * Each action returned a bare view() and shared no `seo` array, so all four
 * fell through to the defaults in partials/head: the site title and the site
 * description, four times over. Four public URLs, one title between them —
 * and Google, given four pages that introduce themselves identically, picks
 * one and ignores the rest.
 *
 * The licences page is the one that stings. "Can I use this sound
 * commercially" is a real search with real intent, and that page is the
 * answer to it; it was introducing itself as the catalogue's front door.
 *
 * The descriptions here are written for the result, not for the page: they
 * answer the question somebody typed, in the words they typed it.
 */
class LegalController extends Controller
{
    public function terms(): View
    {
        view()->share('seo', [
            'title' => 'Terms of use',
            'description' => 'What you agree to when you use dbelo: accounts, downloads, what the licences cover, '
                .'and what happens if a sound turns out not to be ours to give.',
        ]);

        return view('legal.terms');
    }

    public function privacy(): View
    {
        view()->share('seo', [
            'title' => 'Privacy policy',
            'description' => 'What dbelo stores about you, why, for how long, and how to have it deleted. '
                .'Written to be read rather than to be survived.',
        ]);

        return view('legal.privacy');
    }

    public function contributor(): View
    {
        view()->share('seo', [
            'title' => 'Contributor agreement',
            'description' => 'The terms for uploading your own recordings to dbelo: what you keep, what you grant, '
                .'and how to take a sound back down.',
        ]);

        return view('legal.contributor');
    }

    public function licenses(): View
    {
        view()->share('seo', [
            // The title people would actually type. "Licences" alone is what
            // the page is called; "what you can do with the sounds" is what
            // they are looking for.
            'title' => 'Sound effect licences — what you can use them for',
            'description' => 'Every dbelo licence in plain words: commercial use, attribution, YouTube and client work, '
                .'and the handful of things no licence here allows.',
        ]);

        return view('legal.licenses', [
            'licenses' => License::orderBy('id')->get(),
        ]);
    }
}
