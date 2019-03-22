<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\ScoreLog;
use App\Models\ScoreRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ScoreLogServe
{
    /**
     * 登录
     * @param User $user
     * @return bool
     */
    public function loginAddScore(User $user)
    {
        $now = Carbon::now();
        $today = Carbon::today();

        /**
         * @var $ids Collection
         * 每天都有一个登录用户的 key,通过定时任务删除
         * 如果这个用户已经记录过了,那么可以跳过
         */
        $cacheKey = $this->loginKey($today->toDateString());
        $ids = Cache::get($cacheKey, collect());

        if ($ids->contains($user->id)) {
            return false;
        }

        $ids->push($user->id);
        Cache::put($cacheKey, $ids, 60*24);

        // 每次登录总是送这么多积分
        $rule = ScoreRule::query()->where('index_code', ScoreRule::INDEX_LOGIN)->firstOrFail();

        $user->score_all += $rule->score;
        $user->score_now += $rule->score;

        $scoreLog = new ScoreLog();
        $scoreLog->rule_id = $rule->id;
        $scoreLog->user_id = $user->id;
        $scoreLog->score = $rule->score;
        $scoreLog->description = str_replace(':time', $now->toDateTimeString(), $rule->replace_text);
        $scoreLog->save();

        // 看是否达到连续登录的条件
        $lastLoginDate = Carbon::make($user->last_login_date);
        // 如果是连续登录,那么久就加多一天,否则重置为一天
        $user->login_days = $today->copy()->subDay()->eq($lastLoginDate) ? $user->login_days + 1 : 1;
        $user->last_login_date = $today->toDateString();


        // 看是否能达到连续登录送积分
        $continueLoginRule = ScoreRule::query()
                                      ->where('index_code', ScoreRule::INDEX_CONTINUE_LOGIN)
                                      ->where('times', $user->login_days)
                                      ->first();
        // 如果满足了连续登录的要求
        if ($continueLoginRule) {

            $firstDay = $today->copy()->subDay($continueLoginRule->times)->toDateString();

            $user->score_all += $continueLoginRule->score;
            $user->score_now += $continueLoginRule->score;

            $scoreLog = new ScoreLog();
            $scoreLog->rule_id = $continueLoginRule->id;
            $scoreLog->user_id = $user->id;
            $scoreLog->score = $continueLoginRule->score;
            $scoreLog->description = str_replace(
                [':start_date', ':end_date', ':days天'],
                [$firstDay, $today->toDateString(), $continueLoginRule->times],
                $continueLoginRule->replace_text
            );
            $scoreLog->save();
        }

        return $user->save();
    }

    /**
     * 浏览商品增加积分
     *
     * @param User    $user
     * @param Product $product
     * @return void
     */
    public function browseProductAddScore(User $user, Product $product)
    {
        $today = Carbon::today();

        /**
         * @var $browseProducts Collection
         * @var $userBrowseProducts Collection
         * 每天都有一个登录用户的 key,通过定时任务删除
         * 如果这个用户已经记录过了,那么可以跳过
         */
        $cacheKey = $this->browseKey($today->toDateString());

        // 浏览的格式如下
        // ['user1_id' => [1, 2, 3, 4]]
        $browseProducts = Cache::get($cacheKey, collect());
        $userBrowseProducts = $browseProducts->get($user->id, collect());

        // 如果用户今天已经浏览过了这个商品,那么就不会再记录
        if ($userBrowseProducts->contains($product->id)) {
            return;
        }

        $browseProducts->put($user->id, $userBrowseProducts->push($product->id));
        Cache::put($cacheKey, $browseProducts, 60*24);


        // TODO 后续优化缓存起来
        // 查询是否达到增加积分
        $rule = ScoreRule::query()
                         ->where('index_code', ScoreRule::INDEX_REVIEW_PRODUCT)
                         ->where('times', $userBrowseProducts->count())
                         ->first();


        if ($rule) {

            $user->score_all += $rule->score;
            $user->score_now += $rule->score;
            $user->save();

            $scoreLog = new ScoreLog();
            $scoreLog->rule_id = $rule->id;
            $scoreLog->user_id = $user->id;
            $scoreLog->score = $rule->score;
            $scoreLog->description = str_replace(
                [':date', ':number'],
                [$today->toDateString(), $rule->times],
                $rule->replace_text
            );
            $scoreLog->save();
        }
    }

    /**
     * 完成订单增加积分
     *
     * @param Order $order
     */
    public function completeOrderAddSCore(Order $order)
    {
        // 订单完成增加积分
        $rule = ScoreRule::query()
                         ->where('index_code', ScoreRule::INDEX_COMPLETE_ORDER)
                         ->firstOrFail();

        // 计算积分和钱的比例
        $addScore = ceil($order->total * $rule->score);

        $user = $order->user;
        $user->score_all += $rule->score;
        $user->score_now += $rule->score;
        $user->save();

        $scoreLog = new ScoreLog();
        $scoreLog->rule_id = $rule->id;
        $scoreLog->user_id = $user->id;
        $scoreLog->score = $addScore;
        $scoreLog->description = str_replace(
            [':time', ':no'],
            [Carbon::now()->toDateTimeString(), $order->no],
            $rule->replace_text
        );
        $scoreLog->save();
    }

    public function loginKey($date)
    {
        return "{$date}_login_users";
    }

    public function browseKey($date)
    {
        return "{$date}_browse_products";
    }
}