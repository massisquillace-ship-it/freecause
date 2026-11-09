<?php

namespace App\Http\Controllers;

use App\Services\HomepageData;
use App\Services\HomepageMagazine;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        return view('pages.home', array_merge(
            app(HomepageData::class)->forRequest($request),
            ['magazinePosts' => app(HomepageMagazine::class)->posts(app()->getLocale())]
        ));
    }
}
