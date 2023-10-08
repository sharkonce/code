<?php

namespace App\Services;

use App\Models\CommissionRecord;
use App\Models\InviteRecord;
use App\Models\LuckyHistory;
use App\Models\LuckyMoney;
use App\Models\ShareRecord;
use App\Models\TgUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * author [@cody](https://t.me/cody0512)
 */
class TgUserService
{

    public function __construct()
    {

    }

    public static function addUser($memberInfo,$groupId,$inviteUserId = 0)
    {
        $info = TgUser::query()->where('tg_id', $memberInfo->id)->where('group_id', $groupId)->first();
        if (!$info) {
            $default_balance = ConfigService::getConfigValue($groupId, 'default_balance');
            $insert = [
                'username' => $memberInfo->username,
                'first_name' => $memberInfo->first_name,
                'tg_id' => $memberInfo->id,
                'group_id' => $groupId,
                'balance' => $default_balance > 0 ? $default_balance : 0,
                'status' => 1,
                'invite_user' => 0,
            ];
            if ($inviteUserId && $inviteUserId != $memberInfo->id ) {
                $insert['invite_user'] = $inviteUserId;
            }
            $rs = TgUser::query()->create($insert);
            if (!$rs) {
                return ['state' => 0, 'msg' => '注册失败'];
            }
            if ($inviteUserId && $inviteUserId != $memberInfo->id) {
                self::addInviteAmount($inviteUserId, $memberInfo->id, $groupId);
            }
            return ['state' => 1];
        } else {
            if ($inviteUserId && !$info->invite_user) {
                $info->invite_user = $inviteUserId;
                $rs = $info->save();
                if ($rs && $inviteUserId != $memberInfo->id) {
                    self::addInviteAmount($inviteUserId, $memberInfo->id, $groupId);
                }
                if (!$rs) {
                    return ['state' => 0, 'msg' => '注册失败'];
                }
            }

            return ['state' => 2];
        }

    }

    public static function registerUser($memberInfo,$groupId)
    {
        $info = TgUser::query()->where('tg_id', $memberInfo->id)->where('group_id', $groupId)->first();
        if (!$info) {
            $default_balance = ConfigService::getConfigValue($groupId, 'default_balance');
            $insert = [
                'username' => $memberInfo->username,
                'first_name' => $memberInfo->first_name,
                'tg_id' => $memberInfo->id,
                'group_id' => $groupId,
                'balance' =>  $default_balance > 0 ? $default_balance : 0,
                'status' => 1,
                'invite_user' => 0,
            ];
            $rs = TgUser::query()->create($insert);
            if (!$rs) {
                return ['state' => 0, 'msg' => '注册失败'];
            }
        } else if ($info['status'] == 0) {
            $info->status = 1;
            $rs = $info->save();
            if (!$rs) {
                return ['state' => 0, 'msg' => '注册失败'];
            }
        } else if ($info['status'] == 1) {
            return ['state' => 0, 'msg' => '用户已注册'];
        }
        return ['state' => 1];
    }

    public static function addInviteAmount($inviteUserId, $tg_id, $group_id)
    {
        $inviteCount = InviteRecord::query()->where('invite_user_id', $inviteUserId)->where('group_id', $group_id)->where('tg_id', $tg_id)->count();
        if ($inviteCount) {
            return true;
        }
        DB::beginTransaction();
        $amount = ConfigService::getConfigValue($group_id, 'invite_usdt');
        $rs = TgUser::query()->where('tg_id', $inviteUserId)->where('group_id', $group_id)->increment('balance', $amount);
        if ($rs) {
            $insert = [
                'amount' => $amount,
                'tg_id' => $tg_id,
                'group_id' => $group_id,
                'invite_user_id' => $inviteUserId,
                'remark' => '邀请返利',
            ];
            $rsCreate = InviteRecord::query()->create($insert);
            if (!$rsCreate) {
                DB::rollBack();
            }
            DB::commit();
            money_log($group_id,$inviteUserId,$amount,'invite','邀请返利');
            return true;
        }
    }

    public static function leftUser($memberInfo)
    {
        $info = TgUser::query()->where('tg_id', $memberInfo->id)->where('group_id', $memberInfo->group_id)->first();
        if ($info && $info['status'] == 1) {
            $info->status = 0;
            $info->save();
        }
    }

    public static function checkBalance($senderInfo, $amount)
    {
        if (!$senderInfo) {
            return ['state' => 0, 'msg' => '用户不存在'];
        } else if (!$senderInfo->status) {
            return ['state' => 0, 'msg' => '用户已禁用，请联系管理员处理'];
        } else if ($senderInfo->balance < $amount) {
            return ['state' => 0, 'msg' => '您的余额已不足发包'];
        }
        return ['state' => 1];
    }

    public static function getUserById($id, $groupId)
    {
        return TgUser::query()->where('tg_id', $id)->where('group_id', $groupId)->first();

    }

    public static function getShareData($id, $chatId)
    {
        $key = 'share_'.$id.'_'.$chatId;
        $shareData = Cache::get($key);
        if(!$shareData) {
            $todayCount = TgUser::query()->where('invite_user', $id)->where('group_id', $chatId)
                ->where('created_at', '>=', Carbon::now()->startOfDay())
                ->where('created_at', '<=', Carbon::now()->endOfDay())
                ->count();

            $monthCount = TgUser::query()->where('invite_user', $id)->where('group_id', $chatId)
                ->where('created_at', '>=', Carbon::now()->startOfMonth())
                ->where('created_at', '<=', Carbon::now()->endOfMonth())
                ->count();

            $totalCount = TgUser::query()->where('invite_user', $id)->where('group_id', $chatId)
                ->count();
            $inviteUserList = TgUser::query()->where('invite_user', $id)->where('group_id', $chatId)->limit(10)->orderBy('id', 'desc')
                ->get();
            if (!$inviteUserList->isEmpty()) {
                $inviteUserList = $inviteUserList->toArray();
            } else {
                $inviteUserList = [];
            }
            $return = [
                'todayCount' => $todayCount,
                'monthCount' => $monthCount,
                'totalCount' => $totalCount,
                'inviteUserList' => $inviteUserList,
            ];
            Cache::set($key,serialize($return),10);
        }else{
            $return = unserialize($shareData);
        }
        return $return;
    }

    public static function getTodayData($id, $chatId)
    {
        $key = 'today_'.$id.'_'.$chatId;
        $todayData = Cache::get($key);
        if(!$todayData){
            $info = TgUser::query()->where('tg_id', $id)->where('group_id', $chatId)->first();
            if (!$info) {
                return ['state' => 0, 'msg' => '用户不存在'];
            } else if (!$info->status) {
                return ['state' => 0, 'msg' => '用户已禁用，请联系管理员处理'];
            }
            $todayStart = date('Y-m-d 00:00:00');
            $todayEnd = date('Y-m-d H:i:s');
            //红包支出
            $redPayTotal = LuckyMoney::query()->where('sender_id', $id)->where('chat_id', $chatId)
                ->where('created_at', '>=', $todayStart)->where('created_at', '<', $todayEnd)->sum('received');
            //发包盈收
            $sendProfitTotal = LuckyHistory::query()->where('lucky_history.created_at', '>=', $todayStart)->where('lucky_history.created_at', '<', $todayEnd)
                ->leftJoin('lucky_money as lm', 'lm.id', '=', 'lucky_history.lucky_id')
                ->where('lm.sender_id', $id)->where('lm.chat_id', $chatId)->sum('lucky_history.lose_money');
            //抢包收入
            $getProfitTotal = LuckyHistory::query()->where('created_at', '>=', $todayStart)->where('created_at', '<', $todayEnd)
                ->where('user_id', $id)->sum('amount');
            $loseTotal = LuckyHistory::query()->where('created_at', '>=', $todayStart)->where('created_at', '<', $todayEnd)
                ->where('user_id', $id)->sum('lose_money');
            $todayProfit = $sendProfitTotal - $redPayTotal + $getProfitTotal - $loseTotal;

            //邀请返利
            $todayInvite = InviteRecord::query()->where('group_id', $chatId)->where('invite_user_id', $id)
                ->where('created_at', '>=', $todayStart)->where('created_at', '<', $todayEnd)
                ->sum('amount');
            //下级返利
            $todayShare = ShareRecord::query()->where('group_id', $chatId)->where('share_user_id', $id)
                ->where('created_at', '>=', $todayStart)->where('created_at', '<', $todayEnd)
                ->sum('amount');

            //我发包玩家中雷上级代理抽成
//            $todayTopShare = ShareRecord::query()->where('group_id', $chatId)->where('sender_id', $id)
//                ->where('created_at', '>=', $todayStart)->where('created_at', '<', $todayEnd)
//                ->sum('amount');
//
//            //我发包玩家中雷平台抽成
//            $todayPlat = CommissionRecord::query()->where('group_id', $chatId)->where('sender_id', $id)
//                ->where('created_at', '>=', $todayStart)->where('created_at', '<', $todayEnd)
//                ->sum('amount');


            $return = [
                'redPayTotal' => round($redPayTotal, 2),
                'sendProfitTotal' => round($sendProfitTotal, 2),
                'getProfitTotal' => round($getProfitTotal, 2),
                'loseTotal' => round($loseTotal, 2),
                //'todayTopShare' => round($todayTopShare, 2),
                'todayShare' => round($todayShare, 2),
                'todayInvite' => round($todayInvite, 2),
                //'todayPlat' => round($todayPlat, 2),
                'todayProfit' => $todayProfit > 0 ? '+' . round($todayProfit, 2) : round($todayProfit, 2),
            ];
            Cache::set($key,serialize($return),10);
        }else{
            $return = unserialize($todayData);

        }

        return $return;
    }
}
