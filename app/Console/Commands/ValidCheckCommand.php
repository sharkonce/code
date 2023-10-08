<?php

namespace App\Console\Commands;


use App\Jobs\MsgToTelegram;
use App\Models\LuckyMoney;
use App\Models\TgUser;
use App\Services\LuckyMoneyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Attributes\ParseMode;

class ValidCheckCommand extends Command
{
    /** author [@cody](https://t.me/cody0512)
     * 红包过期判断
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'validcheck';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    protected $telegram;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(Nutgram $bot)
    {
        $this->info('开始...');
        $i = 0;
        while (true) {

            $list = LuckyMoneyService::getInValidList();
            if (count($list) > 0) {
                foreach ($list as $item) {
                    $list = LuckyMoneyService::getLuckyHistory($item['id']);
                    $details = '';
                    $loseMoneyTotal = 0;
                    $qiangTotal = 0;
                    foreach ($list as $key => $val) {
                        $qiangTotal += $val['amount'];
                        if ($val['is_thunder'] != 1) {
                            $details .= ($key + 1) . ".[💵] <code>" . number_format(round($val['amount'], 2), 2, '.', '0') . "</code> U <code>" . format_name($val['first_name']) . "</code>\n";
                        } else {
                            $details .= ($key + 1) . ".[💣] <code>" . number_format(round($val['amount'], 2), 2, '.', '0') . "</code> U <code>" . format_name($val['first_name']) . "</code>\n";
                            $loseMoneyTotal += $val['lose_money'];
                        }
                    }
                    $shengyu = round($item['amount'] - $qiangTotal, 2);
                    $shengyuText = $shengyu > 0 ? '(已退回)' : '';
                    $profit = round($loseMoneyTotal + $shengyu - $item['amount'], 2);
                    $profitTxt = $profit >= 0 ? '+' . $profit : $profit;
                    if ($item['type'] == 1) {


                        $caption = "[ <code>" . format_name($item['sender_name']) . "</code> ]的红包已过期！\n
🧧红包金额：" . (int)$item['amount'] . " U
🛎红包倍数：" . round($item['lose_rate'], 2) . "
💥中雷数字：{$item['thunder']}\n
--------领取详情--------\n
" . $details . "
<pre>💹 中雷盈利： " . $loseMoneyTotal . "</pre>
<pre>💹 发包成本：-" . round($item['amount'], 2) . "</pre>
<pre>💹 已领取：" . round($qiangTotal, 2) . "</pre>
<pre>💹 剩余：" . round($shengyu, 2) . $shengyuText . "</pre>
<pre>💹 包主实收：{$profitTxt}</pre>
温馨提示：[ <code>" . format_name($item['sender_name']) . "</code> ]的红包已过期！";

                    } else {

                        $caption = "[ <code>" . format_name($item['sender_name']) . "</code> ]的福利红包已过期！\n
🧧红包金额：" . $item['amount'] . " U

--------领取详情--------\n
" . $details . "
<pre>💹 发包成本：-" . round($item['amount'], 2) . "</pre>
<pre>💹 已领取：" . round($qiangTotal, 2) . "</pre>
<pre>💹 剩余：" . round($shengyu, 2) . $shengyuText . "</pre>
温馨提示：[ <code>" . format_name($item['sender_name']) . "</code> ]的福利红包已过期！";

                    }

                    $data = [
                        'message_id' => $item['message_id'],
                        'chat_id' => $item['chat_id'],
                        'caption' => $caption,
                        'parse_mode' => ParseMode::HTML,
                        'reply_markup' => common_reply_markup($item['chat_id'])
                    ];
                    $num = 3;
                    for ($i = $num; $i >= 0; $i--) {
                        if ($i <= 0) {
                            Log::error('重试3次，过期信息编辑失败');
                            continue 2;
                        }
                        try {
                            $rs = $bot->editMessageCaption($data);
                            $this->info("过期红包信息：message_id={$item['message_id']}  chat_id={$item['chat_id']}" . json_encode($item));
                            $this->doUpdate($item, $shengyu);
                            if (!$rs) {
                                Log::error('过期红包，信息编辑失败');
                            }
                            continue 2;
                        } catch (\Exception $e) {
                            if ($e->getCode() == 429) {
                                $retry_after = $e->getParameter('retry_after');
                                sleep($retry_after);
                            } else {
                                $this->info("过期信息编辑失败，直接更新数据，返还金额。错误信息：" . $e);
                                Log::error('过期信息编辑失败，直接更新数据库。错误信息：' . $e);
                                $this->doUpdate($item, $shengyu);
                                sleep(30);
                                continue 2;
                            }
                        }
                    }
                }
            }
            sleep(30);
            $i++;
            $this->info("循环{$i}次");
        }
    }

    private function doUpdate($item,$shengyu){
        $uRs = LuckyMoney::query()->where('id', $item['id'])->where('status',1)->update(['status' => 3, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($uRs) {
            //删除缓存
            del_lucklist($item['id']);
//            del_history($item['id']);
            $rs1 = TgUser::query()->where('tg_id', $item['sender_id'])->where('group_id', $item['chat_id'])->increment('balance', $shengyu);
            if (!$rs1) {
                LuckyMoney::query()->where('id', $item['id'])->update(['status' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
            }
            money_log($item['chat_id'],$item['sender_id'],$shengyu,'bagback','红包过期返回',$item['id']);
        }
    }

}
