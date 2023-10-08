<?php

namespace App\Services;

use App\Jobs\LuckyHistoryJob;
use App\Jobs\MsgToTelegram;
use App\Models\AuthGroup;
use App\Models\InviteLink;
use App\Models\LuckyHistory;
use App\Models\LuckyMoney;
use App\Models\RechargeRecord;
use App\Models\TgUser;
use App\Models\WithdrawRecord;
use App\Telegram\Middleware\GroupVerify;
use Dcat\Admin\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\RunningMode\Polling;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * author [@cody](https://t.me/cody0512)
 */
class TelegramService
{

    public static function handleRed(Nutgram $bot)
    {

        $bot->group(GroupVerify::class, function (Nutgram $bot) {

            // Your handlers here
            // Called when a message contains the command "/start someParameter"
//            $bot->onCommand('start {parameter}', function (Nutgram $bot, $parameter) {
//                $bot->sendMessage("The parameter is {$parameter}");
//            });
            // ex. called when a message contains "My name is Mario"
            $bot->onText('上分([0-9]+)', function (Nutgram $bot, $amount) {
                $from = $bot->message()->from->id;
                $finance = ConfigService::getConfigValue($bot->chat()->id, 'finance');
                $financeArr = explode(',', $finance);
                if (!in_array($from, $financeArr)) {
                    return false;
                }
                $params = ['parse_mode' => ParseMode::HTML, 'reply_to_message_id' => $bot->message()->message_id];
                if ($amount < 1 || !$amount) {
                    $bot->sendMessage("上分金额错误", $params);
                    return false;
                }
                $reply_to_message = $bot->message()->reply_to_message;
                if (!$reply_to_message) {
                    return false;
                }
                if ($reply_to_message->from->is_bot == true) {
                    return false;
                }
                $username = $reply_to_message->from->first_name != '' ? $reply_to_message->from->first_name : $reply_to_message->from->username;
                $tgId = $reply_to_message->from->id;
                $user = TgUser::query()->where('tg_id', $tgId)->where('group_id', $bot->chat()->id)->first();
                if (!$user) {
                    TgUserService::registerUser($reply_to_message->from, $bot->chat()->id);
                    $user = TgUser::query()->where('tg_id', $tgId)->where('group_id', $bot->chat()->id)->first();
                }
                $balance = $user->balance + $amount;
                try {
                    DB::beginTransaction();
                    $user->balance = $balance;
                    $rs = $user->save();
                    if ($rs) {
                        money_log($user->group_id, $user->tg_id, $amount, 'recharge', '财务上分');
                        $insert = [
                            'tg_id' => $user->tg_id,
                            'username' => $user->username,
                            'first_name' => $user->first_name,
                            'group_id' => $user->group_id,
                            'amount' => $amount,
                            'remark' => '财务上分',
                            'status' => 1,
                            'type' => 3,//财务群聊上分
                            'admin_id' => 0,
                        ];
                        $rs2 = RechargeRecord::query()->create($insert);
                        if (!$rs2) {
                            DB::rollBack();
                            $bot->sendMessage("上分失败，请联系管理员", $params);
                            return false;
                        }
                        DB::commit();
                        $bot->sendMessage("✅ 上分 <b>{$amount}</b> 成功
🔹您的用户: <code>{$username}</code>
🔹您的用户ID: <code>{$tgId}</code>
🔹余额: <b>{$balance}</b> U", $params);
                    }
                } catch (\Exception $e) {
                    Log::error('上分异常:' . $e->getMessage() . ' code=>' . $e->getCode());
                    $bot->sendMessage("上分失败，请联系管理员", $params);
                }
            });
            $bot->onText('下分([0-9]+)', function (Nutgram $bot, $amount) {
                $from = $bot->message()->from->id;
                $finance = ConfigService::getConfigValue($bot->chat()->id, 'finance');
                $financeArr = explode(',', $finance);
                if (!in_array($from, $financeArr)) {
                    return false;
                }
                $params = ['parse_mode' => ParseMode::HTML, 'reply_to_message_id' => $bot->message()->message_id];
                if ($amount < 1 || !$amount) {
                    $bot->sendMessage("下分金额错误", $params);
                    return false;
                }
                $reply_to_message = $bot->message()->reply_to_message;
                if (!$reply_to_message) {
                    return false;
                }
                if ($reply_to_message->from->is_bot == true) {
                    return false;
                }
                $username = $reply_to_message->from->first_name != '' ? $reply_to_message->from->first_name : $reply_to_message->from->username;
                $tgId = $reply_to_message->from->id;
                $user = TgUser::query()->where('tg_id', $tgId)->where('group_id', $bot->chat()->id)->first();
                if (!$user || $amount > $user->balance) {
                    $bot->sendMessage("用户余额不足", $params);
                    return false;
                }
                $balance = $user->balance - $amount;
                try {
                    DB::beginTransaction();
                    $user->balance = $balance;
                    $rs = $user->save();
                    if ($rs) {
                        money_log($user->group_id, $user->tg_id, -$amount, 'withdraw', '财务下分');
                        $insert = [
                            'tg_id' => $user->tg_id,
                            'username' => $user->username,
                            'first_name' => $user->first_name,
                            'group_id' => $user->group_id,
                            'amount' => $amount,
                            'remark' => '财务下分',
                            'status' => 1,
                            'address' => '',
                            'addr_type' => '',
                            'admin_id' => 0,
                        ];
                        $rs2 = WithdrawRecord::query()->create($insert);
                        if (!$rs2) {
                            DB::rollBack();
                            $bot->sendMessage("下分失败，请联系管理员", $params);
                            return false;
                        }
                        DB::commit();
                        $bot->sendMessage("✅ 下分 <b>{$amount}</b> 成功
🔹您的用户:  <code>{$username}</code>
🔹您的用户ID: <code>{$tgId}</code>
🔹余额: <b>{$balance}</b> U", $params);
                    }
                } catch (\Exception $e) {
                    Log::error('下分异常:' . $e->getMessage() . ' code=>' . $e->getCode());
                    $bot->sendMessage("下分失败，请联系管理员", $params);
                }
            });
            $bot->onText('(发[包]*)*([0-9]+\.?[0-9]?)[-/]([0-9]+\.?[0-9]?)', function (Nutgram $bot, $ac, $amount, $mine) {
                $pattern = '/^\d+\.\d+?$/';
                if (preg_match($pattern, $amount)) {
                    try {
                        $bot->sendMessage('指令有误，请输入整数', ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage('指令有误，请输入整数');
                    }
                    return false;
                }
                if (preg_match($pattern, $mine)) {
                    try {
                        $bot->sendMessage('指令有误，请输入整数', ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage('指令有误，请输入整数');
                    }
                    return false;
                }
                if ($mine > 9 || $mine < 0 || $mine == null) {
                    try {
                        $bot->sendMessage('指令有误，雷数应是0~9之间', ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage('指令有误，雷数应是0~9之间');
                    }
                    return false;
                }
                $minAmount = ConfigService::getConfigValue($bot->chat()->id, 'min_amount');
                if ($amount < $minAmount) {
                    try {
                        $bot->sendMessage("红包金额不能小于 {$minAmount} U", ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage("红包金额不能小于 {$minAmount} U");
                    }
                    return false;
                }
                $maxAmount = ConfigService::getConfigValue($bot->chat()->id, 'max_amount');
                if ($amount > $maxAmount) {
                    try {
                        $bot->sendMessage("红包金额不能大于 {$maxAmount} U", ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage("红包金额不能大于 {$maxAmount} U");
                    }
                    return false;
                }
                $amount = (int)$amount;
                $mine = (int)$mine;
                $sendUserId = $bot->user()->id;
                $senderInfo = TgUser::query()->where('tg_id', $sendUserId)->where('group_id', $bot->chat()->id)->first();
                //检查余额
                $checkRs = TgUserService::checkBalance($senderInfo, $amount);
                if ($checkRs['state'] != 1) {
                    try {
                        $bot->sendMessage($checkRs['msg'], ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage($checkRs['msg']);
                    }
                } else {
                    $luckyTotal = ConfigService::getConfigValue($bot->chat()->id, 'lucky_num');

                    DB::beginTransaction();
                    //添加红包
                    $luckyId = LuckyMoneyService::addLucky($senderInfo, $bot->message()->from->first_name, $amount, $mine, $bot->chat()->id, $luckyTotal, 0);
                    if ($luckyId) {
                        $photo = get_photo($bot->chat()->id);
                        if (!$photo) {
                            DB::rollBack();
                            try {
                                $bot->sendMessage('请在后台设置图片！', ['reply_to_message_id' => $bot->message()->message_id]);
                            } catch (\Exception $e) {
                                $bot->sendMessage("请在后台设置图片！");
                            }
                            return false;
                        }
                        $num = 3;
                        for ($i = $num; $i >= 0; $i--) {
                            if ($i <= 0) {
                                Log::error('重试3次，发送红包失败');
                                DB::rollBack();
                                return false;
                            }
                            try {
                                $InlineKeyboardMarkup = InlineKeyboardMarkup::make()->addRow(
                                    InlineKeyboardButton::make("🧧抢红包[{$luckyTotal}/0]总{$amount}U 💥雷{$mine}", callback_data: "qiang-" . $luckyId)
                                );
                                $data = [
                                    'caption' => "[ <code>" . format_name($bot->message()->from->first_name) . "</code> ]发了个 {$amount} U 红包，快来抢！",
                                    'parse_mode' => ParseMode::HTML,
                                    'reply_markup' => common_reply_markup($bot->chat()->id, $InlineKeyboardMarkup),
                                    'reply_to_message_id' => $bot->message()->message_id
                                ];
                                $sendRs = $bot->sendPhoto($photo, $data);
                                if ($sendRs) {
                                    $updateRs = LuckyMoney::query()->where('id', $luckyId)->update(['message_id' => $sendRs->message_id]);
                                    if (!$updateRs) {
                                        DB::rollBack();
                                        $bot->sendMessage('发送失败');
                                        return false;
                                    }
                                    DB::commit();
                                    break;
                                } else {
                                    DB::rollBack();
                                    Log::error('sendPhoto发送失败');
                                    $bot->sendMessage('发送失败');
                                }
                            } catch (\Exception $e) {
                                Log::error('红包发送失败=>code=>' . $e->getCode() . '  msg=>' . $e->getMessage());
                                if ($e->getCode() == 429) {
                                    $retry_after = $e->getParameter('retry_after');
                                    sleep($retry_after);
                                } else {
                                    DB::rollBack();
                                    break;
                                }
                                //throw new TelegramException($e->getMessage(),$e->getCode());
                            }
                        }
                    } else {
                        $bot->sendMessage('发送失败');
                    }


                }


            });
            $bot->onText('福利([0-9]+\.?[0-9]?)[-/]([0-9]+\.?[0-9]?)', function (Nutgram $bot, $amount, $num) {
                $pattern = '/^\d+\.\d+?$/';
                if (preg_match($pattern, $amount)) {
                    try {
                        $bot->sendMessage('指令有误，请输入整数', ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage('指令有误，请输入整数');
                    }
                    return false;
                }
                if (preg_match($pattern, $num)) {
                    try {
                        $bot->sendMessage('指令有误，请输入整数', ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage('指令有误，请输入整数');
                    }
                    return false;
                }
                if ($num < 2 || $num > 100) {
                    try {
                        $bot->sendMessage('福利红包数量不能小于2，大于100', ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage("福利红包数量不能小于2，大于100");
                    }
                    return false;
                }
                if ($amount < 1) {
                    try {
                        $bot->sendMessage('福利红包金额必须大于等于1', ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage("福利红包金额必须大于等于1");
                    }
                    return false;
                }
                if ($amount / $num < 0.1) {
                    try {
                        $bot->sendMessage('福利红包数量太多，发送失败！', ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage("福利红包数量太多，发送失败！");
                    }
                    return false;
                }
                $num = (int)$num;
                $amount = (int)$amount;
                $sendUserId = $bot->user()->id;
                $senderInfo = TgUser::query()->where('tg_id', $sendUserId)->where('group_id', $bot->chat()->id)->first();
                //检查余额
                $checkRs = TgUserService::checkBalance($senderInfo, $amount);
                if ($checkRs['state'] != 1) {
                    try {
                        $bot->sendMessage($checkRs['msg'], ['reply_to_message_id' => $bot->message()->message_id]);
                    } catch (\Exception $e) {
                        $bot->sendMessage($checkRs['msg']);
                    }
                } else {
                    try {
                        DB::beginTransaction();
                        //添加红包
                        $luckyId = LuckyMoneyService::addLucky($senderInfo, $bot->message()->from->first_name, $amount, 0, $bot->chat()->id, $num, 0, 2);
                        if ($luckyId) {
                            $photo = get_photo($bot->chat()->id);
                            if (!$photo) {
                                DB::rollBack();
                                $bot->sendMessage('未设置图片！请联系管理员');
                                return false;
                            }
                            $InlineKeyboardMarkup = InlineKeyboardMarkup::make()->addRow(
                                InlineKeyboardButton::make("🧧【福利红包】快来抢[{$num}/0]总{$amount}U ", callback_data: "qiang-" . $luckyId)
                            );
                            $data = [
                                'caption' => "[ <code>" . format_name($bot->message()->from->first_name) . "</code> ]发了个 {$amount} U 福利红包，快来抢！",
                                'parse_mode' => ParseMode::HTML,
                                'reply_markup' => common_reply_markup($bot->chat()->id, $InlineKeyboardMarkup),
                                'reply_to_message_id' => $bot->message()->message_id
                            ];
                            $sendRs = $bot->sendPhoto($photo, $data);
                            if ($sendRs) {
                                $updateRs = LuckyMoney::query()->where('id', $luckyId)->update(['message_id' => $sendRs->message_id]);
                                if (!$updateRs) {
                                    DB::rollBack();
                                    $bot->sendMessage('发送失败');
                                    return false;
                                }
                                DB::commit();
                            } else {
                                DB::rollBack();
                                $bot->sendMessage('发送失败');
                            }
                        } else {
                            DB::rollBack();
                            $bot->sendMessage('发送失败');
                        }
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error($e);
                    }
                }


            });

            $bot->onText('(1$|查$|余额$|ye$)', function (Nutgram $bot, $ac) {
                if ($ac == '1' || $ac == 'ye' || $ac == '查' || $ac == '余额' || $ac == '查余额') {
                    $reply_to_message = $bot->message()->reply_to_message;
                    if (!$reply_to_message) {
                        $userInfo = TgUserService::getUserById($bot->user()->id, $bot->chat()->id);
                    }else{
                        $from = $bot->message()->from->id;
                        $finance = ConfigService::getConfigValue($bot->chat()->id, 'finance');
                        $financeArr = explode(',', $finance);
                        if (!in_array($from, $financeArr)) {
                            return false;
                        }
                        $userInfo = TgUserService::getUserById($reply_to_message->from->id, $bot->chat()->id);
                    }

                    $params = [];
                    if ($bot->message()->message_id) {
                        $params = ['parse_mode' => ParseMode::HTML, 'reply_to_message_id' => $bot->message()->message_id];
                    }
                    try {
                        try {
                            if (!$userInfo) {
                                $bot->sendMessage("用户未注册", $params);
                            } else {
                                $username = $userInfo->first_name?$userInfo->first_name:$userInfo->username;
                                $bot->sendMessage("💰[ {$username} ] 余额：{$userInfo->balance}  U", $params);
                            }
                        } catch (\Exception $e) {
                            if (!$userInfo) {
                                $bot->sendMessage("用户未注册");
                            } else {
                                $username = $userInfo->first_name?$userInfo->first_name:$userInfo->username;
                                $bot->sendMessage("💰[ {$username} ] 余额：{$userInfo->balance}  U");
                            }
                        }

                    } catch (\Exception $e) {
                        Log::error('查询余额异常' . $e);
                    }
                }
            });

            $bot->onCallbackQueryData('balance', function (Nutgram $bot) {
                $userInfo = TgUserService::getUserById($bot->user()->id, $bot->chat()->id);
                if (!$userInfo) {
                    $bot->answerCallbackQuery([
                        'text' => "用户未注册",
                        'show_alert' => true,
                        'cache_time' => 5
                    ]);

                } else {
                    $bot->answerCallbackQuery([
                        'text' => "{$userInfo->first_name} \n@{$userInfo->username} \n-----------------------------\nID号：{$userInfo->tg_id}\n余额：{$userInfo->balance}  U",
                        'parse_mode' => ParseMode::HTML,
                        'show_alert' => true,
                        'cache_time' => 5
                    ]);
                }

            });
            $bot->onCallbackQueryData('qiang-{luckyid}', function (Nutgram $bot, $luckyid) {
                $userId = $bot->user()->id;
                if (env('QUEUE_CONNECTION') == 'sync') {
                    self::qiangAction($bot, $luckyid, $userId, $bot->message()->message_id, $bot->callbackQuery()?->id);
                } else {
                    $jobData = [
                        'chat_id' => $bot->chat()->id,
                        'lucky_id' => $luckyid,
                        'user_id' => $userId,
                        'message_id' => $bot->message()->message_id,
                        'callback_query_id' => $bot->callbackQuery()?->id,
                    ];
                    MsgToTelegram::dispatch($jobData)->onQueue('qiang');
                }
//                Log::info('qiang=>' . json_encode($bot->message(), JSON_UNESCAPED_UNICODE));
                //

            });

            $bot->onCallbackQueryData('today_data', function (Nutgram $bot) {
                $result = TgUserService::getTodayData($bot->user()->id, $bot->chat()->id);
                $bot->answerCallbackQuery([
                    'text' => "今日盈利：{$result['todayProfit']}
-----------
发包支出：-{$result['redPayTotal']}
发包盈收：+{$result['sendProfitTotal']}
-----------
抢包收入：+{$result['getProfitTotal']}
抢包中雷：-{$result['loseTotal']}
-----------
邀请返利：+{$result['todayInvite']}
下级中雷返点：+{$result['todayShare']}
",
                    'show_alert' => true,
                    'cache_time' => 10
                ]);
            });

            $bot->onCallbackQueryData('share_data', function (Nutgram $bot) {
                $result = TgUserService::getShareData($bot->user()->id, $bot->chat()->id);
                $listTxt = '';
                foreach ($result['inviteUserList'] as $val) {
                    $listTxt .= ($val['first_name'] != '' ? $val['first_name'] : $val['username']) . "\n";
                }
                $bot->answerCallbackQuery([
                    'text' => "今日邀请：" . $result['todayCount'] . "
本月邀请：" . $result['monthCount'] . "
累计邀请：" . $result['totalCount'] . "
-----------
显示最后十条邀请
-----------
" . $listTxt,
                    'show_alert' => true,
                    'cache_time' => 30
                ]);
            });

            /*$bot->onNewChatMembers(function (Nutgram $bot) {
                Log::info('onNewChatMembers==update：'.json_encode($bot->update()));
                $groupId = $bot->chat()->id;
                if(!$bot->message()){
                    return false;
                }
                $Member = $bot->message()->new_chat_members[0];
                if($Member){
                    $inviteTgId = !$bot->message()->from->is_bot ? $bot->message()->from->id : 0;
                    $rs = TgUserService::addUser($Member,$groupId,$inviteTgId);
                    if($rs['state'] == 1 ){
                        //欢迎语
                        $welcomeText = ConfigService::getConfigValue($groupId, 'welcome');
                        if($welcomeText){
                            $bot->sendMessage($welcomeText, ['parse_mode' => ParseMode::HTML]);
                        }
                    }
                }
            });*/


            $bot->onChatMember(function (Nutgram $bot) {
                $groupId = $bot->chat()->id;
                $status = $bot->chatMember()->new_chat_member->status;
                if ($status != 'member') {
                    return false;
                }
                $memberInfo = $bot->chatMember()->new_chat_member->user;
                if (!$memberInfo) {
                    return false;
                }
                $inviteTgId = 0;
                if (isset($bot->chatMember()->from) && $bot->chatMember()->from->id != $memberInfo->id) {
                    $inviteTgId = $bot->chatMember()->from->id;
                }
                if ($bot->chatMember()->invite_link) {
                    $inviteTgId = InviteLink::query()->where('invite_link', $bot->chatMember()->invite_link->invite_link)->value('tg_id');
                }

                $rs = TgUserService::addUser($memberInfo, $groupId, $inviteTgId);
                if ($rs['state'] == 1) {
                    //欢迎语
                    $welcomeText = ConfigService::getConfigValue($groupId, 'welcome');
                    if ($welcomeText) {
                        try {
                            $bot->sendMessage($welcomeText, ['parse_mode' => ParseMode::HTML]);
                        } catch (\Exception $e) {
                            Log::error('onChatMember异常' . $e);
                        }

                    }
                }
                return true;
            });
//            $bot->onLeftChatMember(function (Nutgram $bot) {
//                $groupId = $bot->chat()->id;
//                $Member = $bot->message()->left_chat_member;
//                $Member->group_id = $groupId;
//                TgUserService::leftUser($Member);
//            });


            $bot->onCommand('register(.*)', function (Nutgram $bot) {
                $groupId = $bot->chat()->id;
                $Member = $bot->user();
                $rs = TgUserService::registerUser($Member, $groupId);

                try {
                    if ($rs['state'] == 1) {
                        $bot->sendMessage("注册成功");
                    } else {
                        $bot->sendMessage($rs['msg']);
                    }
                } catch (\Exception $e) {
                    Log::error('register异常' . $e);
                }

            });


            // Called on command "/help"
        });
    }

    public static function qiangAction($bot, $luckyid, $userId, $message_id, $callback_query_id = null)
    {
        $historyListKey = 'history_list_' . $luckyid;
        $historyListLen = Redis::llen($historyListKey);
        if ($historyListLen > 0) {
            for ($i = 0; $i < $historyListLen; $i++) {
                $json = Redis::lindex($historyListKey, $i);
                $historyObj = json_decode($json, true);
                if ($historyObj['user_id'] == $userId) {
                    if ($callback_query_id) {
                        try {
                            $bot->answerCallbackQuery([
                                'text' => '您已经领取该红包，金额 ' . $historyObj['amount'] . ' U',
                                'show_alert' => true,
                                'callback_query_id' => $callback_query_id,
                                'cache_time' => 60
                            ]);
                        } catch (\Exception $e) {
                            Log::error('弹窗消息异常【您已经领取该红包，金额 ' . $historyObj['amount'] . ' U】=>' . $e->getCode() . '  msg=>' . $e->getMessage() . ' line=>' . $e->getLine());
                        }

                    }
                    Log::info('您已经领取该红包，金额 ' . $historyObj['amount'] . ' U');
                    return false;
                }
            }
        }

        $luckyKey = 'lucky_' . $luckyid;
        $luckyInfo = Redis::get('luckyInfo_' . $luckyid);
        if (!$luckyInfo) {
            $luckyInfo = LuckyMoney::query()->where('id', $luckyid)->first();
            if (!$luckyInfo) {
                if ($callback_query_id) {
                    $bot->answerCallbackQuery([
                        'text' => '数据不存在',
                        'show_alert' => true,
                        'callback_query_id' => $callback_query_id,
                        'cache_time' => 60
                    ]);
                }
                return false;
            }
            Redis::setex('luckyInfo_' . $luckyid, 5, serialize($luckyInfo->toArray()));
        } else {
            $luckyInfo = unserialize($luckyInfo);
        }


        $luckyNum = Redis::scard($luckyKey);
        if ($luckyNum == 0) {
            if ($callback_query_id) {
                try {
                    $bot->answerCallbackQuery([
                        'text' => '该红包已全部被领取',
                        'show_alert' => true,
                        'callback_query_id' => $callback_query_id,
                        'cache_time' => 60
                    ]);
                } catch (\Exception $e) {
                    Log::error('弹窗消息异常【该红包已全部被领取】=>' . $e->getCode() . '  msg=>' . $e->getMessage() . ' line=>' . $e->getLine());
                }
            }
            Log::info('该红包已全部被领取');
            return false;
        }

        $userInfo = Redis::get('userInfo_' . $userId . '_' . $luckyInfo['chat_id']);
        if (!$userInfo) {
            $userInfo = TgUser::query()->where('tg_id', $userId)->where('group_id', $luckyInfo['chat_id'])->first();
            Redis::setex('userInfo_' . $userId . '_' . $luckyInfo['chat_id'], 5, serialize($userInfo->toArray()));
        } else {
            $userInfo = unserialize($userInfo);
        }
        $checkRs = LuckyMoneyService::checkLuck($luckyInfo, $userInfo);
        if (!$checkRs['state']) {
            if ($callback_query_id) {
                try {
                    $bot->answerCallbackQuery([
                        'text' => $checkRs['msg'],
                        'show_alert' => true,
                        'callback_query_id' => $callback_query_id,
                    ]);
                } catch (\Exception $e) {
                    Log::error('弹窗消息异常【' . $checkRs['msg'] . '】=>' . $e->getCode() . '  msg=>' . $e->getMessage() . ' line=>' . $e->getLine());
                }

            }
            Log::info('checkLuck=>' . $checkRs['msg']);
            return false;
        }
        if ($userInfo['pass_mine'] == 1) {
            $smembers = Redis::smembers($luckyKey);
            $redAmount = 0;
            foreach ($smembers as $sval) {
                $sval = number_format($sval, 2, '.', '0');
                $isThunder = LuckyMoneyService::checkThunder($sval, $luckyInfo['thunder']);
                if (!$isThunder) {
                    $redAmount = $sval;
                    break;
                }
            }
            if ($redAmount > 0) {
                Redis::srem($luckyKey, $redAmount);
            } else {
                $redAmount = Redis::spop($luckyKey);
            }
        } else if ($userInfo['get_mine'] == 1) {
            $smembers = Redis::smembers($luckyKey);
            $redAmount = 0;
            foreach ($smembers as $sval) {
                $sval = number_format($sval, 2, '.', '0');
                $isThunder = LuckyMoneyService::checkThunder($sval, $luckyInfo['thunder']);
                if ($isThunder) {
                    $redAmount = $sval;
                    break;
                }
            }
            if ($redAmount > 0) {
                Redis::srem($luckyKey, $redAmount);
            } else {
                $redAmount = Redis::spop($luckyKey);
            }
        } else {
            $redAmount = Redis::spop($luckyKey);
        }
        if (!$redAmount) {
            if ($callback_query_id) {
                try {
                    $bot->answerCallbackQuery([
                        'text' => '该红包已全部被领取',
                        'show_alert' => true,
                        'callback_query_id' => $callback_query_id,
                        'cache_time' => 60
                    ]);
                } catch (\Exception $e) {
                    Log::error('弹窗消息异常【该红包已全部被领取1】=>' . $e->getCode() . '  msg=>' . $e->getMessage() . ' line=>' . $e->getLine());
                }
            }
            Log::info('该红包已全部被领取:redAmount=' . $redAmount);
            return false;
        }

        $redAmount = number_format($redAmount, 2, '.', '0');
        $isThunder = LuckyMoneyService::checkThunder($redAmount, $luckyInfo['thunder']);

        $loseMoney = 0;
        if ($isThunder) {
            $loseRate = ConfigService::getConfigValue($luckyInfo['chat_id'], 'lose_rate');
            $loseRate = $loseRate > 0 ? $loseRate : 1.8;
            $loseMoney = round($luckyInfo['amount'] * $loseRate, 2);
            $answerText = "中雷，领取 {$redAmount} U，损失 {$loseMoney} U";
        } else {
            if ($luckyInfo['type'] == 1) {
                $answerText = "💵您抢到 {$redAmount} U 的红包";
            } else {
                $answerText = "恭喜，抢到福利红包 {$redAmount} U";
            }
        }

        if ($callback_query_id) {
            try {
                $bot->answerCallbackQuery([
                    'text' => $answerText,
                    'show_alert' => true,
                    'callback_query_id' => $callback_query_id,
                ]);
                self::editMsg($bot, $userInfo, $luckyInfo, $isThunder, $redAmount, $loseMoney, $message_id);
            } catch (\Exception $e) {
                Redis::sadd($luckyKey, $redAmount);
                Log::error('弹窗消息异常【' . $answerText . '】=>' . $e->getCode() . '  msg=>' . $e->getMessage() . ' line=>' . $e->getLine());
            }
        } else {
            self::editMsg($bot, $userInfo, $luckyInfo, $isThunder, $redAmount, $loseMoney, $message_id);
        }

//        usleep(500000);
        return true;
    }

    public static function editMsg($bot, $userInfo, $luckyInfo, $isThunder, $redAmount, $loseMoney, $message_id)
    {
        $luckyid = $luckyInfo['id'];
        $luckyKey = 'lucky_' . $luckyid;
        $userId = $userInfo['tg_id'];
        $userName = $userInfo['first_name'] != null ? $userInfo['first_name'] : $userInfo['username'];
        $historyVal = [
            'user_id' => $userId,
            'first_name' => $userName,
            'lucky_id' => $luckyid,
            'is_thunder' => $isThunder,
            'amount' => $redAmount,
            'lose_money' => $loseMoney,
        ];
        $historyListKey = 'history_list_' . $luckyid;
        Redis::rpush($historyListKey, json_encode($historyVal));
        $luckyAmount = (int)$luckyInfo['amount'];
        $openNum = Redis::llen($historyListKey);
        Log::info('打开数量=> ' . $openNum);
        if ($luckyInfo['number'] > $openNum) {
            $titleText = '福利红包';
            $thunderText = '';
            $qiangText = '🧧【福利红包】快来抢';
            if ($luckyInfo['type'] == 1) {
                $thunderText = "💥雷{$luckyInfo['thunder']}";
                $qiangText = "🧧抢红包";
                $titleText = "红包";
            }

            $InlineKeyboardMarkup = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make("{$qiangText}[{$luckyInfo['number']}/{$openNum}] 总{$luckyAmount}U {$thunderText}", callback_data: "qiang-" . $luckyid)
            );
            $data = [
                'message_id' => $message_id,
                'caption' => "[ <code>" . format_name($luckyInfo['sender_name']) . "</code> ]发了个 {$luckyAmount} U {$titleText}，快来抢！",
                'parse_mode' => ParseMode::HTML,
                'reply_markup' => common_reply_markup($luckyInfo['chat_id'], $InlineKeyboardMarkup),
                'chat_id' => $luckyInfo['chat_id']
            ];
            try {
                $bot->editMessageCaption($data);

            } catch (\Exception $e) {
                Log::error('抢包修改消息异常=>' . $e->getCode() . '  msg=>' . $e->getMessage() . ' line=>' . $e->getLine());
//                Redis::lpush($luckyKey, $redAmount);
//                Redis::rpop($historyListKey);
            }
            if (env('QUEUE_CONNECTION') == 'sync') {
                self::addHistory($bot, $luckyid, $userId, $redAmount, $isThunder, $loseMoney);
            } else {
                $historyData = [
                    'luckyid' => $luckyid,
                    'userId' => $userId,
                    'loseMoney' => $loseMoney,
                    'isThunder' => $isThunder,
                    'redAmount' => $redAmount,
                ];
                LuckyHistoryJob::dispatch($historyData)->onQueue('history');
            }
        } else {
            $details = '';
            $loseMoneyTotal = 0;
            for ($j = 1; $j <= $openNum; $j++) {
                $valJson = Redis::lindex($historyListKey, $j - 1);
                $val = json_decode($valJson, true);
                if ($val['is_thunder'] != 1) {
                    $details .= $j . ".[💵] <code>" . number_format(round($val['amount'], 2), 2, '.', '0') . "</code> U <code>" . format_name($val['first_name']) . "</code>\n";
                } else {
                    $details .= $j . ".[💣] <code>" . number_format(round($val['amount'], 2), 2, '.', '0') . "</code> U <code>" . format_name($val['first_name']) . "</code>\n";
                    $loseMoneyTotal += $val['lose_money'];
                }
            }

            $profit = $loseMoneyTotal - $luckyInfo['amount'];
            $profitTxt = $profit >= 0 ? '+' . $profit : $profit;

            if ($luckyInfo['type'] == 1) {
                $caption = "[ <code>" . format_name($luckyInfo['sender_name']) . "</code> ]的红包已被领完！\n
🧧红包金额：" . $luckyAmount . " U
🛎红包倍数：" . round($luckyInfo['lose_rate'], 2) . "
💥中雷数字：{$luckyInfo['thunder']}\n
--------领取详情--------\n
" . $details . "
<pre>💹 中雷盈利： " . round($loseMoneyTotal, 2) . "</pre>
<pre>💹 发包成本：-" . $luckyAmount . "</pre>
<pre>💹 包主实收：{$profitTxt}</pre>";
            } else {
                $caption = "[ <code>" . format_name($luckyInfo['sender_name']) . "</code> ]的福利红包已被领完！\n
🧧红包金额：" . $luckyInfo['amount'] . " U
\n
--------领取详情--------\n
" . $details . "
<pre>💹 发包成本：-" . $luckyAmount . "</pre>
";
            }
            $data = [
                'message_id' => $message_id,
                'caption' => $caption,
                'parse_mode' => ParseMode::HTML,
                'reply_markup' => common_reply_markup($luckyInfo['chat_id']),
                'chat_id' => $luckyInfo['chat_id']
            ];
            $num = 3;
            for ($i = $num; $i >= 0; $i--) {
                if ($i <= 0) {
                    Log::error('重试3次，抢包完成修改失败');
                    return false;
                }
                try {
                    $bot->editMessageCaption($data);
                    Redis::del($historyListKey);
                    if (env('QUEUE_CONNECTION') == 'sync') {
                        self::addHistory($bot, $luckyid, $userId, $redAmount, $isThunder, $loseMoney);
                    } else {
                        $historyData = [
                            'luckyid' => $luckyid,
                            'userId' => $userId,
                            'loseMoney' => $loseMoney,
                            'isThunder' => $isThunder,
                            'redAmount' => $redAmount,
                        ];
                        LuckyHistoryJob::dispatch($historyData)->onQueue('history');
                    }
                    break;
                } catch (\Exception $e) {
                    Log::error('抢包完成修改消息异常=>' . $e->getCode() . '  msg=>' . $e->getMessage() . ' line=>' . $e->getLine());
                    if ($e->getCode() == 429) {
                        $retry_after = $e->getParameter('retry_after');
                        sleep($retry_after);
                    } else {
                        Redis::sadd($luckyKey, $redAmount);
                        Redis::rpop($historyListKey);
                        break;
                    }
                }
            }


        }
        return true;

    }

    public static function addHistory($bot, $luckyid, $userId, $redAmount, $isThunder, $loseMoney)
    {
        DB::beginTransaction();
        $luckyInfo = LuckyMoney::query()->where('id', $luckyid)->first();
        if ($luckyInfo['status'] != 1) {
            Log::error('addHistory红包已领完或者已过期');
            DB::rollBack();
            return false;
        }

        $userInfo = TgUser::query()->where('tg_id', $userId)->where('group_id', $luckyInfo['chat_id'])->first();
        $userName = $userInfo['first_name'] != null ? $userInfo['first_name'] : $userInfo['username'];

        $openNum = LuckyHistory::query()->where('lucky_id', $luckyid)->count();
        $historyRs = LuckyMoneyService::addLuckyHistory($userInfo['tg_id'], $userName, $luckyInfo['id'], $isThunder, $redAmount, $loseMoney);
        if ($historyRs) {
            if ($luckyInfo['number'] <= $openNum + 1) {
                $luckyInfo->status = 2;
            }
            $luckyInfo->received = round($luckyInfo['received'] + (float)$redAmount, 2);
            $luckyInfo->received_num = $luckyInfo['received_num'] + 1;
            $rsR = $luckyInfo->save();
            if (!$rsR) {
                Log::error('save 更新失败');
                DB::rollBack();
                return false;
            }
            DB::commit();
        } else {
            Log::error('addLuckyHistory 领取失败');
            DB::rollBack();
            return false;
        }
        LuckyMoneyService::loseMoneyCal($userId, $luckyInfo, $loseMoney);
        LuckyMoneyService::getCal($userId, $luckyInfo, $redAmount);
        //判断是否是豹子
        if ($luckyInfo['type'] == 1 && isset($redAmount) && leopard_check($redAmount)) {
            $amountCount = amount_count($redAmount);
            switch ($amountCount) {
                case 4:
                    $leopardReward = ConfigService::getConfigValue($luckyInfo['chat_id'], 'leopard_reward_4');
                    break;
                case 5:
                    $leopardReward = ConfigService::getConfigValue($luckyInfo['chat_id'], 'leopard_reward_5');
                    break;
                case 3:
                default:
                    $leopardReward = ConfigService::getConfigValue($luckyInfo['chat_id'], 'leopard_reward');
                    break;
            }
            if ($leopardReward > 0) {
                $bot->sendMessage("🎉🎉[  {$userName}  ] 抢到豹子{$redAmount} 奖励:{$leopardReward} 已到账.", ['chat_id' => $luckyInfo['chat_id'], 'parse_mode' => ParseMode::HTML]);
                LuckyMoneyService::addRewardRecord($luckyid, $luckyInfo['sender_id'], $userId, $luckyInfo['chat_id'], $leopardReward, $redAmount, 1);
            }
        }
        //判断是否是顺子
        if ($luckyInfo['type'] == 1 && isset($redAmount) && straight_check($redAmount)) {
            $amountCount = amount_count($redAmount);
            switch ($amountCount) {
                case 4:
                    $straightReward = ConfigService::getConfigValue($luckyInfo['chat_id'], 'straight_reward_4');
                    break;
                case 5:
                    $straightReward = ConfigService::getConfigValue($luckyInfo['chat_id'], 'straight_reward_5');
                    break;
                case 3:
                default:
                    $straightReward = ConfigService::getConfigValue($luckyInfo['chat_id'], 'straight_reward');
                    break;
            }
            if ($straightReward > 0) {
                $bot->sendMessage("🎉🎉[  {$userName}  ] 抢到顺子{$redAmount} 奖励:{$straightReward} 已到账.", ['chat_id' => $luckyInfo['chat_id'], 'parse_mode' => ParseMode::HTML]);
                LuckyMoneyService::addRewardRecord($luckyid, $luckyInfo['sender_id'], $userId, $luckyInfo['chat_id'], $straightReward, $redAmount, 2);
            }
        }
        return true;
    }

}
