<?php



class auth {
  
  function generate($username, $password){
    $user = new dbo('user');
    $user->username = $username;
    $user->password = md5($password);
    $user->find(true);
    if (!$user->id) return false;

    $token_data = [];
    $token_data['id'] = $user->id;
    $token_data['username'] = $user->username;
    $token_data['time'] = time();

    $token_data = json_encode($token_data);

    $keys = $this->keys();
    openssl_public_encrypt($token_data, $encrypted_data, $keys->public);

    return base64_encode($encrypted_data);

  }


  function deep_validate($data){
    $data = $this->validate($data);

    $data = base64_decode($data);
    openssl_private_decrypt($data, $decrypted_data, $keys->private);

    $data = json_decode($decrypted_data);

    $user = new dbo('user',intval($data->id));
    if (!$user->id) return false;

    return $data;
  }


  function validate($data){
    $keys = $this->keys();

    $data = base64_decode($data);
    openssl_private_decrypt($data, $decrypted_data, $keys->private);

    $data = json_decode($decrypted_data);

    if (!isset($data->id)) return false;

    return $data;
  }




  function keys(){

    $keys = new stdClass();

    $keydir = __DIR__."/keys";

    if (!is_dir($keydir)) mkdir($keydir);

    if (!is_file("$keydir/private")){
      $config = array(
        "digest_alg" => "sha512",
        "private_key_bits" => 4096,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
      );
          
      // Create the private and public key
      $res = openssl_pkey_new($config);

      // Extract the private key from $res to $privKey
      openssl_pkey_export($res, $private);
      $public = openssl_pkey_get_details($res);
      $public = $public["key"];
      
      file_put_contents("$keydir/private", $private);
      file_put_contents("$keydir/public", $public);

      $keys->private = $private;
      $keys->public = $public;

    } else {
      $keys->private = file_get_contents("$keydir/private");
      $keys->public = file_get_contents("$keydir/public");

    }


    return $keys;
  }
}