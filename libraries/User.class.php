<?php
/**
 * @Author: printempw
 * @Date:   2016-01-16 23:01:33
 * @Last Modified by:   printempw
 * @Last Modified time: 2016-04-02 22:50:16
 */

use Database\Database;

class User
{
    public $uname   = "";
    private $passwd = "";
    private $token  = "";

    public $db = null;
    public $is_registered = false;
    public $is_admin = false;

    function __construct($uname) {
        $this->uname = Utils::convertString($uname);
        $class_name = "Database\\".Option::get('data_adapter')."Database";
        $this->db = new $class_name('users');

        if ($this->db->sync($this->uname)) {
            $this->passwd = $this->db->select('username', $this->uname)['password'];
            $this->token = md5($this->uname . $this->passwd . SALT);
            $this->is_registered = true;
            if ($this->db->select('username', $this->uname)['uid'] == 1) {
                $this->is_admin = true;
            }
        }
    }

    public function checkPasswd($raw_passwd) {
        if ($this->db->encryptPassword($raw_passwd, $this->uname) == $this->passwd) {
            return true;
        } else {
            return false;
        }
    }

    public static function checkValidUname($uname) {
        return preg_match("/^([A-Za-z0-9_]+)$/", $uname);
    }

    public static function checkValidPwd($passwd) {
        if (strlen($passwd) > 16 || strlen($passwd) < 5) {
            throw new E('无效的密码。密码中包含了奇怪的字符。', 2);
        } else if (Utils::convertString($passwd) != $passwd) {
            throw new E('无效的密码。密码长度应该大于 6 并小于 15。', 2);
        }
        return true;
    }

    public function changePasswd($new_passwd) {
        $this->db->update('password', $this->db->encryptPassword($new_passwd, $this->uname), ['where' => "username='$this->uname'"]);
        $this->db->sync($this->uname, true);
    }

    public function getToken() {
        return $this->token;
    }

    public function register($passwd, $ip) {
        $data = array(
                    "username"   => $this->uname,
                    "password"   => $this->db->encryptPassword($passwd),
                    "ip"         => $ip,
                    "preference" => 'default'
                );
        return $this->db->insert($data);
    }

    public function unRegister() {
        $skin_type_map = ["steve", "alex", "cape"];
        for ($i = 0; $i <= 2; $i++) {
            if ($this->getTexture($skin_type_map[$i]) != "" && !Utils::checkTextureOccupied($this->getTexture($skin_type_map[$i])))
                Utils::remove("./textures/".$this->getTexture($skin_type_map[$i]));
        }
        return $this->db->delete(['where' => "username='$this->uname'"]);
    }

    public function reset() {
        $skin_type_map = ["steve", "alex", "cape"];
        for ($i = 0; $i <= 2; $i++) {
            if ($this->getTexture($skin_type_map[$i]) != "" && !Utils::checkTextureOccupied($this->getTexture($skin_type_map[$i])))
                Utils::remove("./textures/".$this->getTexture($skin_type_map[$i]));
            $this->db->update('hash_'.$skin_type_map[$i], '', ['where' => "username='$this->uname'"]);
        }
        return $this->db->update('preference', 'default', ['where' => "username='$this->uname'"]);
    }

    /**
     * Get textures of user
     * @param  string $type steve|alex|cape, 'skin' for texture of preferred model
     * @return string sha256-hash of texture file
     */
    public function getTexture($type) {
        if ($type == "skin")
            $type = ($this->getPreference() == "default") ? "steve" : "alex";
        if ($type == "steve" | $type == "alex" | $type == "cape")
            return $this->db->select('username', $this->uname)['hash_'.$type];
        return false;
    }

    public function getBinaryTexture($type) {
        if ($this->getTexture($type) != "") {
            $filename = BASE_DIR."/textures/".$this->getTexture($type);
            if (file_exists($filename)) {
                header('Content-Type: image/png');
                // Cache friendly
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $this->getLastModified()).' GMT');
                header('Content-Length: '.filesize($filename));
                return Utils::fread($filename);
            } else {
                header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
                throw new E('请求的贴图已被删除。', 404, true);
            }
        } else {
            header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
            throw new E('该用户尚未上传请求的贴图类型 '.$type.'。', 404, true);
        }
    }

    /**
     * Get avatar that generate from skin of user
     *
     * @param  int    $size  [description]
     * @param  string $model, steve|alex
     * @return null
     */
    public function getAvatar($size, $model=null) {
        if (is_null($model))
            $model = ($this->getPreference() == "default") ? "steve" : "alex";
        // output image directly
        if ($this->getTexture($model) != "") {
            $png = Utils::generateAvatarFromSkin($this->getTexture($model), $size);
            header('Content-Type: image/png');
            imagepng($png);
            imagedestroy($png);
        } else {
            header('Content-Type: image/png');
            echo Utils::fread(BASE_DIR."/assets/images/steve-avatar.png");
        }
    }

    public function setTexture($type, $file) {
        // Remove the original texture first
        if ($this->getTexture($type) != "" && !Utils::checkTextureOccupied($this->getTexture($type)))
            Utils::remove("./textures/".$this->getTexture($type));
        $this->updateLastModified();
        $hash = Utils::upload($file);
        if ($type == "steve" | $type == "alex" | $type == "cape")
            return $this->db->update('hash_'.$type, $hash, ['where' => "username='$this->uname'"]);
        return false;
    }

    /**
     * Set preferred model
     * @param string $type, 'slim' or 'default'
     */
    public function setPreference($type) {
        return $this->db->update('preference', $type, ['where' => "username='$this->uname'"]);
    }

    public function getPreference() {
        return $this->db->select('username', $this->uname)['preference'];
    }

    /**
     * Get JSON profile
     * @param  int $api_type, which API to use, 0 for CustomSkinAPI, 1 for UniSkinAPI
     * @return string, user profile in json format
     */
    public function getJsonProfile($api_type) {
        header('Content-type: application/json');
        if ($this->is_registered) {
            // Support both CustomSkinLoader API & UniSkinAPI
            if ($api_type == 0 || $api_type == 1) {
                $json[($api_type == 0) ? 'username' : 'player_name'] = $this->uname;
                $model = $this->getPreference();
                $sec_model = ($model == 'default') ? 'slim' : 'default';
                if ($api_type == 1) {
                    $json['last_update'] = $this->getLastModified();
                    $json['model_preference'] = [$model, $sec_model];
                }
                if ($this->getTexture('steve') || $this->getTexture('alex')) {
                    // Skins dict order by preference model
                    $json['skins'][$model] = $this->getTexture($model == "default" ? "steve" : "alex");
                    $json['skins'][$sec_model] = $this->getTexture($sec_model == "default" ? "steve" : "alex");
                }
                $json['cape'] = $this->getTexture('cape');
            } else {
                throw new E('配置文件错误：不支持的 API_TYPE。', -1, true);
            }
        } else {
            header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
            $json['errno'] = 1;
            $json['msg'] = "Non-existent user.";
        }
        return json_encode($json, JSON_PRETTY_PRINT);
    }

    public function updateLastModified() {//$this->uname
        // @see http://stackoverflow.com/questions/2215354/php-date-format-when-inserting-into-datetime-in-mysql
        return $this->db->update('last_modified', date("Y-m-d H:i:s"), ['where' => "username='$this->uname'"]);
    }

    /**
     * Get last modified time
     * @return timestamp
     */
    public function getLastModified() {
        return strtotime($this->db->select('username', $this->uname)['last_modified']);
    }

}