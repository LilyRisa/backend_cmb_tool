<?php

namespace App\Http\Controllers;

class Api404Controller extends Controller
{
    public function __invoke()
    {
        abort(404);
    }
}
