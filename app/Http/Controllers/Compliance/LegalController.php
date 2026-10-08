<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class LegalController extends Controller
{
    public function privacyPage(): View
    {
        return view('legal.show', ['document' => __('legal.privacy')]);
    }

    public function termsPage(): View
    {
        return view('legal.show', ['document' => __('legal.terms')]);
    }

    public function cookiePage(): View
    {
        return view('legal.show', ['document' => $this->cookieDocument()]);
    }

    public function privacyPolicy(): JsonResponse
    {
        return response()->json(['data' => __('legal.privacy')]);
    }

    public function terms(): JsonResponse
    {
        return response()->json(['data' => __('legal.terms')]);
    }

    public function cookiePolicy(): JsonResponse
    {
        return response()->json(['data' => $this->cookieDocument()]);
    }

    /** @return array<string, mixed> */
    private function cookieDocument(): array
    {
        return [
            'title' => __('legal.cookies.title'),
            'body' => __('legal.cookies.body'),
            'sections' => [[
                'heading' => __('legal.cookies.preferences'),
                'paragraphs' => [],
                'items' => [
                    __('legal.cookies.strictly_necessary').' ('.__('legal.cookies.always_on').')',
                    __('legal.cookies.functional'),
                    __('legal.cookies.analytics'),
                    __('legal.cookies.marketing'),
                ],
            ]],
            'buttons' => [
                __('legal.cookies.accept_all'),
                __('legal.cookies.reject_non_essential'),
                __('legal.cookies.preferences'),
            ],
        ];
    }
}
