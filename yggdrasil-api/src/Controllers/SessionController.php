<?php

namespace Yggdrasil\Controllers;

use App\Models\Player;
use App\Models\User;
use Cache;
use DB;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Log;
use Schema;
use Yggdrasil\Exceptions\ForbiddenOperationException;
use Yggdrasil\Models\Profile;
use Yggdrasil\Models\Token;

class SessionController extends Controller
{
    public function joinServer(Request $request)
    {
        $accessToken = $request->input('accessToken');
        $selectedProfile = $request->input('selectedProfile');
        $serverId = $request->input('serverId');

        Log::channel('ygg')->info("Player [$selectedProfile] is trying to join server [$serverId] with access token [$accessToken]");

        $result = DB::table('uuid')->where('uuid', $selectedProfile)->first();

        if (!$result) {
            //  Mojang 在这种情况下是会返回 403 的
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.uuid', ['profile' => $selectedProfile]));
        }

        $player = Player::where('name', $result->name)->first();

        if (!$player) {
            // 删除已失效的 UUID 映射（e.g. 其对应的角色已被删除）
            DB::table('uuid')->where('uuid', $selectedProfile)->delete();

            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.uuid', ['profile' => $selectedProfile]));
        }

        $identification = strtolower($player->user->email);

        Log::channel('ygg')->info("Player [$selectedProfile]'s name is [$player->name], belongs to user [$identification]");

        $token = Token::lookup($accessToken);
        if ($token && $token->isValid()) {
            Log::channel('ygg')->info("All access tokens issued for user [$identification] are as listed", [$token]);

            if ($token->accessToken != $accessToken) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.invalid'));
            }

            if ($token->profileId != $selectedProfile) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.player.not-matched'));
            }

            if ($player->user->permission == User::BANNED) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.banned'));
            }

            // 加入服务器
            static::executeWithLock("SERVER_$serverId", function () use ($serverId, $selectedProfile) {
                Cache::forever("SERVER_$serverId", $selectedProfile);
            });
        } elseif ($this->mojangVerified($player) && $this->validateMojang($accessToken)) {
            if ($player->user->permission == User::BANNED) {
                throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.user.banned'));
            }

            Log::channel('ygg')->info("Player [$player->name] is joining server with Mojang verified account.");
            // 加入服务器
            static::executeWithLock("SERVER_$serverId", function () use ($serverId, $selectedProfile) {
                Cache::forever("SERVER_$serverId", $selectedProfile);
            });
        } else {
            // 指定角色所属的用户没有签发任何令牌
            throw new ForbiddenOperationException(trans('Yggdrasil::exceptions.token.missing'));
        }

        Log::channel('ygg')->info("Player [$selectedProfile] successfully joined the server [$serverId]");

        ygg_log([
            'action' => 'join',
            'user_id' => $player->uid,
            'player_id' => $player->pid,
            'parameters' => json_encode($request->except('accessToken')),
        ]);

        return response()->noContent();
    }

    public function hasJoinedServer(Request $request)
    {
        $name = $request->input('username');
        $serverId = $request->input('serverId');
        $ip = $request->input('ip');

        Log::channel('ygg')->info("Checking if player [$name] has joined the server [$serverId] with IP [$ip]");

        $ret = static::executeWithLock("SERVER_$serverId", function () use ($serverId, $name, $ip, $request) {
            // 检查是否进行过 join 请求
            if ($selectedProfile = Cache::get("SERVER_$serverId")) {
                $profile = Profile::createFromUuid($selectedProfile);

                // TODO: 检查 IP 地址
                if ($name === $profile->name) {
                    // 检查完成后马上删除缓存键值对
                    Cache::forget("SERVER_$serverId");
                    Log::channel('ygg')->info("Player [$name] was in the server [$serverId]");

                    // 这里返回的 Profile 必须带材质的数据签名
                    $response = $profile->serialize(false);
                    Log::channel('ygg')->info("Returning player [$name]'s profile", [$response]);

                    ygg_log(array_merge([
                        'action' => 'has_joined',
                        'user_id' => $profile->player->uid,
                        'player_id' => $profile->player->pid,
                        'parameters' => json_encode($request->except('username')),
                    ], ($ip ? compact('ip') : [])));

                    return response()->json()->setContent($response);
                }
            }
        }, 4);
        if ($ret) {
            return $ret;
        }

        Log::channel('ygg')->info("Player [$name] was not in the server [$serverId]");

        return response()->noContent();
    }

    protected function mojangVerified($player)
    {
        if (!Schema::hasTable('mojang_verifications')) {
            return false;
        }

        return DB::table('mojang_verifications')->where('user_id', $player->uid)->exists();
    }

    protected function validateMojang($accessToken)
    {
        try {
            $response = Http::post('https://authserver.mojang.com/validate', [
                'json' => ['accessToken' => $accessToken],
            ]);

            return $response->status() === 204;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected static function isLockSupported(): bool
    {
        switch (config('cache.default')) {
            case 'redis':
            case 'database':
            case 'array':
            case 'memcached':
            case 'dynamodb':
                return true;
            default:
                return false;
        }
    }

    protected static function executeWithLock(
        string $prefix,
        $callback,
        int $seconds = 0
    ) {
        if (static::isLockSupported()) {
            $name = "$prefix-lock";
            $lock = Cache::lock($name);

            return $seconds === 0
                ? $lock->get($callback)
                : $lock->block($seconds, $callback);
        }

        return $callback();
    }
}