<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FacebookAuthController
{
    public function callback(Request $request)
    {
        if (!$request->has('code')) {
            return response('Erro: código de autorização não encontrado.', 400);
        }

        $code = $request->input('code');

        $response = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
            'client_id' => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
            'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),
            'code' => $code,
        ]);

        if ($response->failed()) {
            return response('Erro ao trocar o código por token.', 500);
        }

        $data = $response->json();
        $data['state'] = $request->input('state');
        $accessToken = $data['access_token'];

        $responseToken = Http::get('GET https://graph.facebook.com/v19.0/me/accounts?access_token='.$accessToken);
        \Log::info($data);
        \Log::info($responseToken);
        return redirect(route('admin.settings.index'));
    }

    public function redirectAuth()
    {
        return view('integration.facebook-ads')->with('app_url', str_replace('https://', '', env('APP_URL')));
    }
}
