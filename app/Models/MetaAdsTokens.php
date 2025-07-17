<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetaAdsTokens extends Model
{
    protected $table = 'meta_ads_tokens';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'app_url_customer',
        'access_token',
        'page_id',
        'page_name',
    ];
}
