<?php

namespace App\Services;

use App\Models\CommissionRecord;
use App\Models\Config;
use App\Models\LuckyHistory;
use App\Models\LuckyMoney;
use App\Models\RewardRecord;
use App\Models\ShareRecord;
use App\Models\TgUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use function Sodium\add;

/**
 * author [@cody](https://t.me/cody0512)
 */
class LuckyMoneyService
{
    public function __construct()
    {

    }

    public static function addLucky($senderInfo, $senderName, $amount, $thunder, $chatId, $luckyTotal, $messageId, $type = 1)
    {
        if ($amount < 1) {
            return false;
        }
        DB::beginTransaction();
        $totalAmount = $amount; // 红包总金额
        $totalCount = $luckyTotal; // 红包总个数
        $minAmount = 0.1; // 每个红包最小金额
        $maxAmount = $totalAmount / $totalCount * 2; // 每个红包最大金额
        $chance = \App\Services\ConfigService::getConfigValue($chatId, 'thunder_chance'); //生成30%的事件
        if ($senderInfo['has_thunder'] == 1) {
            $chance = 100;
        }
        if ($senderInfo['no_thunder'] == 1) {
            $chance = 0;
        }
        if ($type == 2) {
            $chance = 0;
        }

        $redEnvelopes = red_envelope($totalAmount, $totalCount, $minAmount, $maxAmount, $thunder,$chatId, $chance);
        $insert = [
            'sender_id' => $senderInfo['tg_id'],
            'sender_name' => $senderName,
            'amount' => $amount,
            'number' => $totalCount,
            'lucky' => 1,
            'received' => 0,
            'thunder' => $thunder,
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'type' => $type,
            'lose_rate' => ConfigService::getConfigValue($chatId, 'lose_rate'),
            'red_list' => json_encode($redEnvelopes),
        ];
        $rs = LuckyMoney::query()->create($insert);
        if ($rs) {
            $rs2 = TgUser::query()->where('tg_id', $senderInfo->tg_id)->where('group_id', $chatId)->decrement('balance', $amount);
            if (!$rs2) {
                DB::rollBack();
            }
            self::addRedisList($redEnvelopes, $rs->id);
            DB::commit();
            money_log($chatId,$senderInfo->tg_id,-$amount,'sendbag','发包',$rs->id);
            return $rs->id;
        } else {
            DB::rollBack();
        }

        return false;
    }

    private static function addRedisList($list, $luckyId)
    {
        $key = 'lucky_' . $luckyId;
        if (Redis::scard($key) > 0) {
            return false;
        }
        foreach ($list as $val) {
//            Redis::rpush($key, $val);
            Redis::sadd($key,$val);
        }
        return true;
    }

    public static function getLuckyHistory($luckyId)
    {
        return LuckyHistory::query()->where('lucky_id', $luckyId)->get();
    }

    public static function checkLuck($lucky, $userInfo)
    {
        if (!$userInfo) {
            return ['state' => 0, 'msg' => '用户未注册，请先注册!命令：/register'];
        }

        if (!$userInfo['status']) {
            return ['state' => 0, 'msg' => '用户已禁用，请联系管理员处理'];
        }
        $selfLucky = ConfigService::getConfigValue($lucky['chat_id'], 'self_lucky');
        if (!$selfLucky && $lucky['sender_id'] == $userInfo['tg_id']) {
            return ['state' => 0, 'msg' => '无法抢自己发的红包'];
        }
//        $historyCount = LuckyHistory::query()->where('lucky_id', $lucky['id'])->where('user_id', $userId)->count();
//        if ($historyCount > 0) {
//            return ['state' => 0, 'msg' => '您已领取该红包，无法再领取'];
//        }
        if ($lucky['type'] == 1) {
            $bRs = self::checkBalance($lucky, $userInfo);
            if (!$bRs['state']) {
                return $bRs;
            }
        }
        return ['state' => 1];
    }

    public static function checkThunder($redAmount, $thunder)
    {
        $is_thunder = 0;
        $lastNum = substr($redAmount, strlen($redAmount) - 1, 1);
        if ($lastNum == $thunder) {
            $is_thunder = 1;
        }
        return $is_thunder;
    }

    public static function addCommission($lucky_id, $sender_id, $tg_id, $group_id, $amount, $profitAmount, $remark)
    {
        if ($amount <= 0) {
            return true;
        }
        $insert = [
            'lucky_id' => $lucky_id,
            'profit_amount' => $profitAmount,
            'amount' => $amount,
            'tg_id' => $tg_id,
            'group_id' => $group_id,
            'remark' => $remark,
            'sender_id' => $sender_id,
        ];
        return CommissionRecord::query()->create($insert);
    }

    //中雷金额计算
    public static function loseMoneyCal($userId, $lucky, $loseMoney)
    {
        if ($loseMoney <= 0) {
            return true;
        }
        $rs1 = TgUser::query()->where('tg_id', $userId)->where('group_id', $lucky['chat_id'])->decrement('balance', $loseMoney);
        if (!$rs1) {
            return false;
        }
        money_log($lucky['chat_id'],$userId,-$loseMoney,'thunderlose','中雷损失',$lucky['id']);
        //平台抽成
        $platformCommission = ConfigService::getConfigValue($lucky['chat_id'], 'platform_commission');
        $platformCommissionAmount = 0;
        if ($platformCommission > 0) {
            $platformCommissionAmount = $loseMoney * $platformCommission / 100;
        }

        $senderOwn = round($loseMoney - $platformCommissionAmount, 2);

        //上级抽成
        $shareRate = ConfigService::getConfigValue($lucky['chat_id'], 'share_rate');
        $shareUserId = TgUser::query()->where('tg_id', $lucky['sender_id'])->where('group_id', $lucky['chat_id'])->value('invite_user');
        $shareRateAmount = 0;
        if($shareUserId && $shareUserId != $lucky['sender_id']){
            $shareRateAmount = $loseMoney * $shareRate / 100;
            $senderOwn = round($senderOwn - $shareRateAmount, 2);
        }


        $rs2 = TgUser::query()->where('tg_id', $lucky['sender_id'])->where('group_id', $lucky['chat_id'])->increment('balance', $senderOwn);
        if (!$rs2) {
            return false;
        }
        money_log($lucky['chat_id'],$lucky['sender_id'],$loseMoney,'bagprofit','发包中雷盈利',$lucky['id']);
        if ($platformCommissionAmount > 0) {
            $rs3 = self::addCommission($lucky->id, $lucky['sender_id'], $userId, $lucky['chat_id'], $platformCommissionAmount, $loseMoney, '包主盈利抽成，比例' . $platformCommission . '%');
            if (!$rs3) {
                return false;
            }
            money_log($lucky['chat_id'],$lucky['sender_id'],-$platformCommissionAmount,'commission','平台抽成',$lucky['id']);
        }
        if ($shareRateAmount > 0 && $shareUserId && $shareUserId != $lucky['sender_id']) {
            $rs4 = self::addShareCommission($shareUserId,$lucky->id, $lucky['sender_id'], $userId, $lucky['chat_id'], $shareRateAmount, $loseMoney, '包主盈利上级抽成，比例' . $shareRate . '%');
            if (!$rs4) {
                return false;
            }

        }
        return true;
    }

    //抢方金额处理
    public static function getCal($userId, $lucky, $redAmount)
    {
        //抢方抽成
        $platformGetCommission = ConfigService::getConfigValue($lucky['chat_id'], 'platform_get_commission');
        $platformGetCommission = (int)$platformGetCommission;
        $platformGetCommissionAmount = 0;
        if ($platformGetCommission > 0) {
            $platformGetCommissionAmount = $redAmount * $platformGetCommission / 100;//平台抽成
        }

        $redAmountOwn = round($redAmount - $platformGetCommissionAmount, 2);
        $rs1 = TgUser::query()->where('tg_id', $userId)->where('group_id', $lucky['chat_id'])->increment('balance', $redAmountOwn);
        if (!$rs1) {
            return false;
        }
        money_log($lucky['chat_id'],$userId,$redAmount,'getprofit','抢包盈利',$lucky['id']);
        if ($platformGetCommissionAmount > 0) {
            $rs2 = self::addCommission($lucky->id, $lucky['sender_id'], $userId, $lucky['chat_id'], $platformGetCommissionAmount, $redAmount, '抢方抢包抽成，比例' . $platformGetCommission . '%');
            if (!$rs2) {
                return false;
            }
            money_log($lucky['chat_id'],$userId,-$platformGetCommissionAmount,'getcommission','抢方抢包抽成',$lucky['id']);
        }
        return true;

    }
    //包主盈利上级抽成
    public static function addShareCommission($shareUserId,$lucky_id, $sender_id, $tg_id, $group_id, $amount, $profitAmount, $remark)
    {

        if ($shareUserId && $shareUserId != $sender_id) {
            DB::beginTransaction();
            $rs = TgUser::query()->where('tg_id', $shareUserId)->where('group_id', $group_id)->increment('balance', $amount);
            if ($rs) {
                $insert = [
                    'lucky_id' => $lucky_id,
                    'sender_id' => $sender_id,
                    'amount' => $amount,
                    'profit_amount' => $profitAmount,
                    'tg_id' => $tg_id,
                    'group_id' => $group_id,
                    'share_user_id' => $shareUserId,
                    'remark' => $remark,
                ];
                $rsCreate = ShareRecord::query()->create($insert);
                if (!$rsCreate) {
                    DB::rollBack();
                    return false;
                }
                DB::commit();
                money_log($group_id,$shareUserId,$amount,'bagprofit','代理抽成',$lucky_id);
                money_log($group_id,$sender_id,-$amount,'sharecommission','上级抽成',$lucky_id);
                return true;
            } else {
                return false;
            }
        }
        return true;
    }

    public static function addLuckyHistory($userId, $first_name, $luckyId, $is_thunder, $redAmount, $loseMoney)
    {
        $insert = [
            'user_id' => $userId,
            'first_name' => $first_name,
            'lucky_id' => $luckyId,
            'is_thunder' => $is_thunder,
            'amount' => $redAmount,
            'lose_money' => $loseMoney,
        ];
        return LuckyHistory::query()->create($insert);
    }

    public static function addRewardRecord($lucky_id, $sender_id, $tg_id, $group_id, $amount, $reward_num, $type)
    {
        $insert = [
            'lucky_id' => $lucky_id,
            'sender_id' => $sender_id,
            'amount' => $amount,
            'tg_id' => $tg_id,
            'group_id' => $group_id,
            'reward_num' => $reward_num,
            'type' => $type,
        ];
        return RewardRecord::query()->create($insert);
    }

    public static function checkBalance($luckyInfo, $userInfo)
    {
        $loseRate = ConfigService::getConfigValue($luckyInfo['chat_id'], 'lose_rate');
        $lowestAmount = $luckyInfo['amount'] * $loseRate;
        if ($userInfo['balance'] < $lowestAmount) {
            return ['state' => 0, 'msg' => '余额不足，需要余额>=' . $lowestAmount . '。或者状态异常~'];
        }
        return ['state' => 1];
    }

    public static function getInValidList()
    {
        $list = [];
        $luckyList = LuckyMoney::query()->where('status', 1)->get();
        if (!$luckyList->isEmpty()) {
            foreach ($luckyList->toArray() as $lucy) {
                $validTime = ConfigService::getConfigValue($lucy['chat_id'], 'valid_time');
                if ($lucy['created_at'] < date('Y-m-d H:i:s', strtotime("- {$validTime}seconds")) && $lucy['amount'] > $lucy['received']) {
                    $list[] = $lucy;
                }
            }
        }

        return $list;
    }

    public static function getValidList()
    {
        $list = [];
        $luckyList = LuckyMoney::query()->where('status', 1)->get();
        if (!$luckyList->isEmpty()) {
            foreach ($luckyList->toArray() as $lucy) {
                $validTime = ConfigService::getConfigValue($lucy['chat_id'], 'valid_time');
                if ($lucy['created_at'] > date('Y-m-d H:i:s', strtotime("- {$validTime}seconds")) && $lucy['amount'] > $lucy['received']) {
                    $list[] = $lucy;
                }
            }
        }

        return $list;
    }

}
