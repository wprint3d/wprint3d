<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SessionController extends Controller
{

    public function id(): Response {
        return response(content: session()->getId());
    }

}
