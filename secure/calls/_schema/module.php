<?php

class api_module extends api_super {

	function _get($request) {

    $model_protections = json_decode(file_get_contents("$this->libdir/protected.models.json"));

		$table = $request->pathParams->table ? $request->pathParams->table : false;

		# special request for all available models
		if (!$table){#$table == 'available_models') {
			$t = new dbo();
			$t->query("show tables");

			$ts = [];
			while ($t->fetch()){
				$table_name = array_values(get_object_vars($t->row))[0];
				if ($model_protections->$table_name->show === false) continue;
				$ts[] = $table_name;
			}

			$this->respond($ts);
    }


    # They are requesting a specific table...
		$t = new dbo($table);
		$fields = $t->get_schema();

		# start building the response;
		$response = [];

    # look for a read permission
    $default_read = strstr($model_protections->$table->permissions, "r");
		# make the fields lower case...
		foreach ($fields as $key => $field) {
			$field = (array)$fields[$key];
      $field_name = $field['Field'];
			if (
        !$model_protections->$table
        || $default_read && !isset($model_protections->$table->fields->$field_name) # if the default is read and there is no field override
        || strstr($model_protections->$table->fields->$field_name,'r')) # field override says yes to read
      { 
        $fields[$key] = $field;
        foreach ($fields[$key] as $k => $v)
          $response[$key][strtolower($k)] = $v;
      }
		}
		$this->respond(array_values($response));
	}

}

?>