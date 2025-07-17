<?php

namespace App\Http\Controllers;

use App\Models\MetaAdsTokens;
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

        $response = Http::get('https://graph.facebook.com/v23.0/oauth/access_token', [
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

        $responseToken = Http::get('https://graph.facebook.com/v23.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => env('FACEBOOK_CLIENT_ID'),
            'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
            'fb_exchange_token' => $accessToken,
        ]);

        $token = json_decode($responseToken, true);
        
        $responseBigToken = Http::get('https://graph.facebook.com/v19.0/me/accounts', [
            'access_token' => $token['access_token']
        ]);

        $bigToken = json_decode($responseBigToken, true);

        $metaAdsTokens = MetaAdsTokens::create([
            'app_url_customer' => $data['state'],
            'access_token' => $bigToken['data']['access_token'],
            'page_id' => $bigToken['data']['id'],
            'page_name' => $bigToken['data']['name'],
        ]);

        if ($metaAdsTokens) {
            return view('integration.facebook-ads-redirect-app-customer')->with('app_url', $data['state']);
        }

        return redirect(route('admin.settings.index'));
    }

    public function redirectAuth()
    {
        return view('integration.facebook-ads')->with('app_url', str_replace('https://', '', env('APP_URL')));
    }
}
