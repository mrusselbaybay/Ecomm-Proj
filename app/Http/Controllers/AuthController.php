<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function index()
    {
        return view('auth.app', [
            'config' => [
                'supabase_url' => config('services.supabase.url'),
                'supabase_anon_key' => config('services.supabase.anon_key'),
            ]
        ]);
    }
}