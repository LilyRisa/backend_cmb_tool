<?php

namespace App\Http\Controllers;

use App\Models\Tool;

class LandingController extends Controller
{
    public function __invoke()
    {
        $latestTool = Tool::query()
            ->where('type', 'cmb_core')
            ->where('is_active', true)
            ->where('is_latest', true)
            ->first();

        return view('cmb-landing', ['latestTool' => $latestTool]);
    }
}
