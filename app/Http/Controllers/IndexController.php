<?php

namespace App\Http\Controllers;

use App\Models\LuckyHistory;
use App\Models\TgUser;
use App\Services\ConfigService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

class IndexController extends BaseController
{
    public function index(){
        $amount = 11.11;
        $amount = number_format($amount,2,'.',0);
        $res = leopard_check($amount);
        echo $res?'yes':'no';

    }

    private function sum($redEnvelopes){

    }
}
