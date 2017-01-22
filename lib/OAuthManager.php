<?php

/**
 * OAuthManager is used by the auth.php for configuring the
 * plugin.
 */
class OAuthManager {
    private $azp;
    private $info;
    private $key;
    private $keyList;

    public function __construct($azp_id=null) {
        $this->azp = $azp_id;
        if (isset($azp_id)) {
            $this->info = $this->get();
            if (!isset($this->info)) {
                throw new Exception("authorization not found");
            }
        }
    }

    public function aud() {
        return $CFG->wwwroot ."/auth/oauth2/cb.php";
    }

    public function findById($id) {
        $this->azp = $id;
        return $this->get();
    }

    public function findByUrl($url) {
        global $DB;
        if (!empty($url)) {
            if ($rec = $DB->get_record("auth_oauth_azp", ["url" => $url])) {
                $this->azp = $rec->id;
            }
        }
    }

    public function isNew() {
        return !($this->azp > 0);
    }

    public function getPrivateKey() {
        global $DB;
        $keyinfo = $DB->get_record("auth_oauth_keys", [
            "kid" => "private",
            "azp_id" => $this->azp,
            "token_id"  => null,
            "jku"    => null
        ]);

        if (!$keyinfo) {
            throw new Exception("no private key found for azp");
        }
        return $keyinfo;
    }

    public function setPrivateKey($key) {
        global $DB;
        // error_log("key is $key");
        try {
            $ki = $this->getPrivateKey();
        }
        catch (Exception $err) {
            $ki = null;
        }

        if (!$ki) {
            $DB->insert_record("auth_oauth_keys", [
                "kid" => "private",
                "crypt_key" => $key,
                "azp_id" => $this->azp
            ]);
        }
        else {
            $DB->update_record("auth_oauth_keys", [
                "id"  => $ki->id,
                "crypt_key" => $key
            ]);
        }
        // check if we can find the private key, which throws an error
        try {
            $ki = $this->getPrivateKey();
        }
        catch (Exception $err) {
            $ki = null;
        }
        if ($ki && $ki->crypt_key !== $key) {
            throw new Exception("Error Storing Private Key");
        }
    }

    public function getAuthorities() {
        global $DB;
        return $DB->get_records("auth_oauth_azp");
    }

    public function get() {
        global $DB;
        if (isset($this->azp)) {
            return $DB->get_record("auth_oauth_azp", ["id" => $this->azp]);
        }
        return null;
    }

    public function store($info) {
        global $DB;

        foreach (["name", "url", "client_id"] as $attr) {
            if (!array_key_exists($attr, $info)) {
                throw new Exception("attribute missing");
            }
        }
        foreach ( array_keys($info) as $attr) {
            if (!in_array($attr, ["name", "url", "client_id", "flow", "auth_type", "credentials", "iss"])) {
                unset($info[$attr]);
            }
        }

        if (isset($this->azp) && $this->azp > 0) {
            $info["id"] = $this->azp;
            $DB->update_record("auth_oauth_azp", $info);
        }
        else {
            // add default attribute mapping on creation.
            if (!array_key_exists("attr_map", $info) || empty($info["attr_map"])) {
                $info["attr_map"] = $this->getDefaultMapping();
            }

            $DB->insert_record("auth_oauth_azp", $info);
        }
    }

    public function remove() {
        global $DB;
        if ($this->azp > 0) {
            $DB->delete_records("auth_oauth_azp", ["id" => $this->azp]);

            // verify that the authority is gone
            $i = $DB->get_record("auth_oauth_azp", ["id" => $this->azp]);
            if ($i && $i->id == $this->azp) {
                throw new Exception("Azp Not Removed");
            }
        }
    }

    public function deactivate() {
        global $DB;
        if ($this->azp > 0) {
            $DB->update_record("auth_oauth_azp", ["id" => $this->azp, "inactive" => 1]);
        }
    }

    public function activate() {
        global $DB;
        if ($this->azp > 0) {
            $DB->update_record("auth_oauth_azp", ["id" => $this->azp, "inactive" => 0]);
        }
    }

    public function getKeys() {
        global $DB;
        return $DB->get_records("auth_oauth_keys", ["azp_id" => $this->azp]);
    }


    public function getKey($keyid) {
        global $DB;
        return $DB->get_record("auth_oauth_keys", ["azp_id" => $this->azp, "id" => $keyid]);
    }

    public function getValidationKey($kid, $jku) {
        global $DB;
        if (empty($kid) && empty($jku)) {
            return null;
        }
        $attr = ["kid" => $kid, "jku" => $jku];
        if (empty($kid)) {
            $attr["kid"] = null;
        }
        if (empty($jku)) {
            $attr["jku"] = null;
        }
        $keyset = $DB->get_record("auth_oauth_keys", $attr);
        $this->azp = $keyset->azp_id;
        return $keyset;
    }

    public function storeKey($keyInfo) {
        global $DB;
        $keyInfo = (array) $keyInfo;
        foreach (["kid", "crypt_key"] as $k) {
            if (!array_key_exists($k, $keyInfo)) {
                throw new Exception("Missing Key Attribute $k ". json_encode($keyInfo));
            }
        }
        if (array_key_exists("keyid", $keyInfo)) {
            if (!empty($keyInfo["keyid"])) {
                $keyInfo["id"] = $keyInfo["keyid"];
            }
            unset($keyInfo["keyid"]);
        }

        foreach (array_keys($keyInfo) as $attr) {
            if (!in_array($attr, ["crypt_key", "id", "kid", "jku", "token_id"])) {
                unset($keyInfo[$attr]);
            }
        }
        $keyInfo["azp_id"] = $this->azp;

        if (array_key_exists("id", $keyInfo )) {
            $DB->update_record("auth_oauth_keys", $keyInfo);
        }
        else {
            $DB->insert_record("auth_oauth_keys", $keyInfo);
        }
    }

    public function removeKey($id) {
        global $DB;

        $DB->delete_records("auth_oauth_keys", ["id" => $id, "azp_id" => $this->azp_id]);
    }

    public function createState($info) {
        if (!$this->azp) {
            return null;
        }

        // create random string using moodle's random string function
        $attr = [
            "id" => random_string(15),
            "azp_id" => $this->azp
        ];

        if (!empty($info)) {
            foreach (["token", "token_id", "refresh_id", "redirect_uri"] as $k) {
                $attr[$k] = $info[$k];
            }
        }
        $DB->insert_record("auth_oauth_state", $attr);

        return $attr["id"];
    }

    public function getState($state) {
        global $DB;

        $rec = $DB->get_record("auth_oauth_state", ["id" => $state]);
        $this->azp = $rec->azp_id;
        return $rec;
    }

    public function consumeState($state) {
        global $DB;
        $DB->delete_records("auth_oauth_state", ["id" => $state]);
    }

    public function getMapping() {
        if ($rec = $this->get() && !empty($rec->attr_map)) {
            try {
                $mapping = json_decode($rec->attr_map, true);
            }
            catch (Exception $err) {
                return $this->getDefaultMapping();
            }
            return $mapping;
        }
        return $this->getDefaultMapping();
    }

    public function getDefaultMapping() {
        return [
            "email" => "email",
            "firstname" => "given_name",
            "lastname" => "family_name",
            "idnumber" => "",
            "icq" => "",
            "skype" => "",
            "yahoo" => "",
            "aim" => "",
            "msn" => "",
            "phone1" => "phone_number",
            "phone2" => "",
            "institution" => "",
            "departement" => "",
            "address" => "address.street_address",
            "city" => "address.locality",
            "country" => "address.country",
            "lang" => "locale",
            "url" => "website",
            "middlename" => "middle_name",
            "firstnamephonetic" => "",
            "lastnamephonetic" => "",
            "alternatename" => "nickname"
        ];
    }

    public function storeToken($token) {
        global $DB;
        $funcname = "update_record";
        if (!array_key_exists("id", $token)) {
            $token["initial_access_token"] = $token["access_token"];
            $token["initial_refresh_token"] = $token["refresh_token"];
            $funcname = "insert_record";
        }

        $DB->$funcname("auth_oauth_tokens", $token);
    }

    public function getTokenById($tokenId) {
        global $DB;
        $retval = $DB->get_record("auth_oauth_tokens", ["id"=>$tokenId]);
        if ($retval) {
            return (array) $retval;
        }
        return null;
    }

    public function getToken($token, $type) {
        global $DB;
        $attr = [
            $type => $token
        ];
        $retval = $DB->get_record("auth_oauth_tokens", $attr);
        if ($retval) {
            return (array) $retval;
        }
        return null;
    }
}