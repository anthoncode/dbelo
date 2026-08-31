<?php

namespace App\Http\Controllers;

use App\Models\License;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function terms(): View
    {
        return view('legal.terms');
    }

    public function privacy(): View
    {
        return view('legal.privacy');
    }

    public function contributor(): View
    {
        return view('legal.contributor');
    }

    public function licenses(): View
    {
        return view('legal.licenses', [
            'licenses' => License::orderBy('id')->get(),
        ]);
    }
}
