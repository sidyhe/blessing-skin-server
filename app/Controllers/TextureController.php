<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\Player;
use App\Models\Texture;
use App\Exceptions\E;
use Minecraft;
use Option;
use Http;

class TextureController extends BaseController
{

    public function json($player_name, $api = "")
    {
        // FUCKING CHINESE PLAYER NAME
        // I DONT WANT TO IMPLEMENT IT AT ALL
        $player_name = Option::get('allow_chinese_playername') ? $GLOBALS['player_name'] : $player_name;

        $player = new Player(0, $player_name);

        if ($player->is_banned)
            Http::abort(404, '该角色拥有者已被本站封禁。');

        if ($api == "csl") {
            echo $player->getJsonProfile(0);
        } else if ($api == "usm") {
            echo $player->getJsonProfile(1);
        } else if ($api == "") {
            echo $player->getJsonProfile(Option::get('api_type'));
        } else {
            Http::abort(404, '不支持的 API_TYPE。');
        }
    }

    public function jsonWithApi($api, $player_name)
    {
        $this->json($player_name, $api);
    }

    public function skin($player_name, $model = "")
    {
        $player_name = Option::get('allow_chinese_playername') ? $GLOBALS['player_name'] : $player_name;

        $player = new Player(0, $player_name);

        if ($player->is_banned)
            Http::abort(404, '该角色拥有者已被本站封禁。');

        if (!$this->checkCache($player_name)) {
            $model_preference = ($player->getPreference() == "default") ? "steve" : "alex";
            $model = ($model == "") ? $model_preference : $model;
            echo $player->getBinaryTexture($model);
        }
    }

    public function cape($player_name)
    {
        $player_name = Option::get('allow_chinese_playername') ? $GLOBALS['player_name'] : $player_name;

        $player = new Player(0, $player_name);

        if ($player->is_banned)
            Http::abort(404, '该角色拥有者已被本站封禁。');

        if (!$this->checkCache($player_name)) {
            echo $player->getBinaryTexture('cape');
        }
    }

    public function avatar($base64_email, $size = 128)
    {
        $user = new User(null, ['email' => base64_decode($base64_email)]);
        echo $user->getAvatar((int)$size);
    }

    public function avatarWithSize($size, $base64_email)
    {
        $this->avatar($base64_email, $size);
    }

    public function preview($tid, $size = 250)
    {
        // output image directly
        if ($t = Texture::find($tid)) {
            $filename = BASE_DIR."/textures/".$t->hash;

            if (\Storage::exist($filename)) {
                header('Content-Type: image/png');

                if ($t->type == "cape") {
                    $png = Minecraft::generatePreviewFromCape($filename, $size);
                    imagepng($png);
                    imagedestroy($png);
                } else {
                    $png = Minecraft::generatePreviewFromSkin($filename, $size);
                    imagepng($png);
                    imagedestroy($png);
                }
            } else {
                Http::abort(404, '该材质文件已被删除');
            }
        } else {
            // Default Steve Skin: https://minecraft.net/images/steve.png
            $skin_steve  = "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";
            $png = Minecraft::generatePreviewFromSkin($skin_steve, $size, true);
            header('Content-Type: image/png');
            imagepng($png);
            imagedestroy($png);
        }
    }

    public function previewWithSize($size, $tid)
    {
        $this->preview($tid, $size);
    }

    public function raw($tid) {
        if ($t = Texture::find($tid)) {
            header('Content-Type: image/png');
            echo \Storage::fread(BASE_DIR."/textures/".$t->hash);
        } else {
            Http::abort(404, '材质不存在');
        }

    }

    public static function redirectTextures($api, $hash)
    {
        Http::redirectPermanently('../../textures/'.$hash);
    }

    private function checkCache($player_name)
    {
        // Cache friendly
        $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ?
                                    strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : null;

        if ($if_modified_since >= (new Player(0, $player_name))->getLastModified()) {
            http_response_code(304);
            return true;
        } else {
            return false;
        }
    }

}
