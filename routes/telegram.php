<?php
/** @var SergiX44\Nutgram\Nutgram $bot */

use App\Models\InviteLink;
use App\Services\ConfigService;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

/*
|--------------------------------------------------------------------------
| Nutgram Handlers
|--------------------------------------------------------------------------
|
| Here is where you can register telegram handlers for Nutgram. These
| handlers are loaded by the NutgramServiceProvider. Enjoy!
|
*/

//$bot->onCommand('start', function (Nutgram $bot) {
//    return $bot->sendMessage('Hello, world!');
//})->description('The start command!');
$bot->onText('(获取群信息ID$)', function (Nutgram $bot, $ac) {
    if ($bot->chat()->type == 'private') {
    }else{
        if($ac=='获取群信息ID'){
            try {
                $params = [
                    'parse_mode' => ParseMode::HTML
                ];
                if (!empty($bot->message()->message_id)) {
                    $params['reply_to_message_id'] = $bot->message()->message_id;
                }
                $bot->sendMessage("群ID：<code>{$bot->chat()->id}</code>\n用户ID：<code>{$bot->user()->id}</code>", $params);
            } catch (\Exception $e) {
                $bot->sendMessage("群ID：<code>{$bot->chat()->id}</code>\n用户ID：<code>{$bot->user()->id}</code>", ['parse_mode' => ParseMode::HTML]);
            }
        }

    }
});
$bot->onPhoto(function (Nutgram $bot) {
    if ($bot->chat()->type == 'private') {
        $fileId = $bot->message()->photo[1]->file_id;
        try {

            $params = [
                'parse_mode' => ParseMode::HTML
            ];
            if (!empty($bot->message()->message_id)) {
                $params['reply_to_message_id'] = $bot->message()->message_id;
            }

            $bot->sendMessage("图片ID：<code>$fileId</code>", $params);

        } catch (\Exception $e) {
            $bot->sendMessage("图片ID：<code>$fileId</code>", ['parse_mode' => ParseMode::HTML]);
        }
    }
});
$bot->onCommand('help(.*)', function (Nutgram $bot) {
    $helpText = ConfigService::getConfigValue($bot->chat()->id, 'help');
    if ($helpText) {
        $params = [
            'parse_mode' => ParseMode::HTML
        ];
        if (!empty($bot->message()->message_id)) {
            $params['reply_to_message_id'] = $bot->message()->message_id;
        }
        try{
            $bot->sendMessage($helpText,$params);
        } catch (\Exception $e) {
            $bot->sendMessage($helpText, ['parse_mode' => ParseMode::HTML]);
        }

    }
});
$bot->onCommand('invite(.*)', function (Nutgram $bot) {
    $chatId = $bot->chat()->id;
    $params = [
        'parse_mode' => ParseMode::HTML
    ];
    if (!empty($bot->message()->message_id)) {
        $params['reply_to_message_id'] = $bot->message()->message_id;
    }
    if (\App\Models\AuthGroup::query()->where('group_id', $bot->chat()->id)->count() == 0) {
        $user = \App\Models\TgUser::query()->where('tg_id', $bot->user()->id)->orderBy('id', 'desc')->first();
        if (!$user) {
            try{
                $bot->sendMessage("用户未注册，请先进群",$params);
            } catch (\Exception $e) {
                $bot->sendMessage("用户未注册，请先进群", ['parse_mode' => ParseMode::HTML]);
            }

            return;
        }
        $chatId = $user->group_id;
    }
    $rs = $bot->createChatInviteLink($chatId);
    if (!$rs->invite_link) {
        try{
            $bot->sendMessage("邀请链接创建失败！请联系管理员",$params);
        } catch (\Exception $e) {
            $bot->sendMessage("邀请链接创建失败！请联系管理员", ['parse_mode' => ParseMode::HTML]);
        }

        return;
    }
    if (InviteLink::query()->where('group_id', $chatId)->where('invite_link', $rs->invite_link)->where('tg_id', $bot->user()->id)->count() == 0) {
        $insert = [
            'tg_id' => $bot->user()->id,
            'group_id' => $chatId,
            'invite_link' => $rs->invite_link,
        ];
        $iRs = InviteLink::query()->create($insert);
        if (!$iRs) {
            try{
                $bot->sendMessage("邀请链接创建失败！请联系管理员",$params);
            } catch (\Exception $e) {
                $bot->sendMessage("邀请链接创建失败！请联系管理员", ['parse_mode' => ParseMode::HTML]);
            }
            return;
        }
    }
    try{
        $bot->sendMessage("您的专属链接为:{$rs->invite_link}
(用户加入自动成为您的下级用户)",$params);
    } catch (\Exception $e) {
        $bot->sendMessage("您的专属链接为:{$rs->invite_link}
(用户加入自动成为您的下级用户)", ['parse_mode' => ParseMode::HTML]);
    }

});

$bot->onCommand('start', function (Nutgram $bot) {
    $text = "👏 欢迎 ID: {$bot->user()->id}";
    $bot->sendMessage($text, ['parse_mode' => ParseMode::HTML]);
});
