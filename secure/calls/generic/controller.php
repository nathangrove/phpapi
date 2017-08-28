<?php


class api_module extends api_super {
  
  function _get($request){

    $model_protections = json_decode(file_get_contents("$this->libdir/protected.models.json"));

    # sanity check...
    if ($request->pathParams->table == '') return false;

    $table = $request->pathParams->table;
    $id = $request->pathParams->id;
    $id = $id == '' ? false : $id;

    if ($id !== false){
      $t = new dbo($table, $id);
      if (!$t) $this->error("Resource not found");
      $t->row = $this->filter($model_protections->$table,$t->row,'r');
      $this->respond($t->row);
    } else {
      $t = new dbo($table);
      $t->find();

      $res = [];
      while ($t->fetch()){
        $res[] = $this->filter($model_protections->$table,$t->row,'r');
      } 

      $this->respond($res);
    }

    return true;
  }


  function _post($request){

    $model_protections = json_decode(file_get_contents("$this->libdir/protected.models.json"));

    # sanity check...
    if ($request->pathParams->table == '') $this->error("Invalid model");
    $table = $request->pathParams->table;

    # filter only the things we can write...
    $_REQUEST = (array)$this->filter($model_protections->$table,(object)$_REQEUST,'w');

    $t = new dbo($table);
    foreach ($_REQUEST as $key => $value)
      $t->$key = $value == '' ? 'NULL' : $value;

    if (!$t->insert()) $this->error($t->err);

    $this->respond($t->row);

    return true;
  }




  function _put($request){

    $model_protections = json_decode(file_get_contents("$this->libdir/protected.models.json"));

    # sanity check...
    if ($request->pathParams->table == '') $this->error("Invalid model");
    if ($request->pathParams->id == '') $this->error("Invalid object");

    $table = $request->pathParams->table;
    $id = $request->pathParams->id;

    # filter only the things we can write...
    $_REQUEST = (array)$this->filter($model_protections->$table,(object)$_REQUEST,'w');
    $t = new dbo($table,$id);
    foreach ($_REQUEST as $key => $value)
      $t->$key = $value == '' ? 'NULL' : $value;

    if (!$t->update()) $this->error($t->err);

    $this->respond($t->row);
    
    return true;
  }
  




  function _delete($request){

    $model_protections = json_decode(file_get_contents("$this->libdir/protected.models.json"));

    # sanity check...
    if ($request->pathParams->table == '' || $request->pathParams->id == '') return false;

    $table = $request->pathParams->table;
    $id = $request->pathParams->id;

    # can we delete?
    if (isset($model_protections->$table) && !strstr($model_protections->$table->permissions,'w')) $this->error("Unauthorized action");

    $t = new dbo($table, $id);
    if(!$t->delete()) $this->error($t->err);
    return true;
  }


  private function filter($permissions,$data,$operation){

    $default_op = strstr($permissions->permissions, $operation);
    foreach ($data as $key => $value){
      if (
        !$permissions
        || $default_op && !isset($permissions->fields->$key) # if the default is read and there is no field override
        || strstr($permissions->fields->$key,$operation)) # field override says yes to read
      { continue; };
      unset($data->$key);
    }
    return $data;
  }



}

?>