<?php

##########################################
# API class
##########################################
class API {



  ################################
  # constructor
  ################################
  function __construct($config, $routes){
    $this->config = $config;
    $this->routes = $routes;

    $this->authorization = false;

    # let's try to parse the request body manually if necessary
    if ($_SERVER['REQUEST_METHOD'] == 'PUT'){
      $str = file_get_contents("php://input");
      $json = json_decode($str);
      # if it was json...then turn it into a single layer array...
      if ($json)
        foreach ($json as $key => $value){
          if (is_array($value) || is_object($value)) $value = json_encode($value);
          $_REQUEST[$key] = $value;
        }

      # if it wasn't json...then try to parse it was a query string...
      if (!$json) $_REQUEST = parse_str($str);


    } elseif ($_SERVER["REQUEST_METHOD"] == "POST" && !count($_POST)){
      $str = file_get_contents("php://input");

      # check if it is json
      $json = json_decode($str);

      if ($json)
        foreach ($json as $key => $value){
          if (is_array($value) || is_object($value)) $value = json_encode($value);
          $_REQUEST[$key] = $value;
        }
    }

    # if they are trying to authorize in the request itself...then allow it...
    if (isset($_REQUEST['authorization'])){
      $this->authorization = $_REQUEST['authorization'];
      unset($_REQUEST['authorizaiton']);
    }


    # check the header for auth token...
    $headers = getallheaders();
    if (isset($headers['authorization'])) $this->authorization = $headers['authorization'];

  }
  ################################





  ################################
  # process
  ################################
  function process(){

    # parse the path...
    parse_str($_SERVER['QUERY_STRING'],$path);
    $this->path = $path['path'];

    # intercept framework handled requests....
    $this->intercept();

    # find our route they are accessing 
    foreach ($this->routes as $route => $controller){

      # check for a match
      $route_check = preg_replace('/:[a-zA-Z0-9_]*/','[a-zA-Z0-9_]*',$route);
      $route_check = str_replace('/','\/?',$route_check);
      $route_check = '/'.$route_check.'/';


      # did we find the proper route?
      if (preg_match($route_check, '/'.$this->path)){

        # get the variables....
        $re = '/(:?[a-zA-Z0-9]+)/';
        preg_match_all($re, $route, $vars);
        $vars = $vars[1]; 
        $path = explode('/',$this->path);

        # filter out what isn't variable data
        $vars = array_filter($vars,function($a){
          if (strpos($a,":") === 0) return true;
          return false;
        });

        # set the variable names
        $vars = array_map(function($a){
          return str_replace(":","",$a);
        },$vars);

        # get our path pathvars...
        $pathvars = [];
        foreach ($vars as $idx => $var)
          $pathvars[$var] = $path[$idx];

        $request = new stdClass();
        $request->pathParams = new stdClass();
        foreach ($pathvars as $key => $val) $request->pathParams->$key = $val;
        $request->queryParams = new stdClass();
        foreach ($_REQUEST as $key => $val) $rquest->queryParams->$key = $val;

        # include the controller...
        include $controller;

        $processor = new api_module();

        # can we call it?
        @$call = $pathvars['call'] . "_" . strtolower($_SERVER['REQUEST_METHOD']);
        if (!is_callable(array($processor,$call))){
          http_response_code(404);
          print "Controller method not found";
          exit;
        }

        # does the call require authorization?
        if ($this->config->auth_default && $processor->{$call."_auth"} !== false){
          $this->authorize();
      
          # set some variables for the processor
          $processor->auth = $this->auth;
          $processor->uid = $this->auth->id;
        }


        # call it...
        $processor->$call($request);
        exit;

      } # if route match
    } # foreach route
    


    # is the auto route option set?
    if ($this->config->auto_route){

      # let's assume the first var is the directory that contains the controller and the second will be an "id" variable...
      $route = explode("/",$this->path);
      $dir = $this->config->app_dir."/calls/".$route[0];
      $id = $route[1];
      $pathvars['id'] = $id;

      $request = new stdClass();
      $request->pathParams = new stdClass();
      foreach ($pathvars as $key => $val) $request->pathParams->$key = $val;
      $request->queryParams = new stdClass();
      foreach ($_REQUEST as $key => $val) $rquest->queryParams->$key = $val;

      # maybe you forgot to register the route? let's try to blindly find the controller file...
      if (is_file("$dir/controller.php") || is_file("$dir/index.php")){

        @include "$dir/controller.php";
        @include "$dir/index.php"; 

        $processor = new api_module();

        $call = "_" . strtolower($_SERVER['REQUEST_METHOD']);
        if (is_callable(array($processor,$call))){

          # we are guessing here...so we will auth...
          $this->authorize();
        
          # set some pathvars for the processor
          $processor->auth = $this->auth;
          $processor->uid = $this->auth->id;

          # call it...
          $processor->$call($request);
          exit;
        }
      }

    }



    # is the generic route option set?
    if ($this->config->generic_route){

      # hmm....we couldn't find the controller...so let's try a generic one....
      include $this->config->app_dir."/calls/generic/controller.php";

      $processor = new api_module();

      $call = "_" . strtolower($_SERVER['REQUEST_METHOD']);
      if (is_callable(array($processor,$call))){
        # we will always try to auth on these calls...
        $this->authorize();

        # set some pathvars for the processor
        $processor->auth = $this->auth;
        $processor->uid = $this->auth->id;

        # let's assume the first var is the table and the second will be an "id" variable...
        $route = explode("/",$this->path);
        $table = $route[0];
        $id = $route[1];
        $pathvars['id'] = $id;
        $pathvars['table'] = $table;

        $request = new stdClass();
        $request->pathParams = new stdClass();
        foreach ($pathvars as $key => $val) $request->pathParams->$key = $val;
        $request->queryParams = new stdClass();
        foreach ($_REQUEST as $key => $val) $rquest->queryParams->$key = $val;

        # call it...
        $res = $processor->$call($request);

        # only exit if the generic was successful...
        if ($res) exit;
      }
    }



    # we have made it here...no routes matched...let's try to dynamically find it...
    http_response_code(404);
    print "Resource not found";


  }
  ################################





  ################################
  # authorize - check if authed user
  ################################
  function authorize($return=false){

    # start with pass...
    $valid = true;

    # init the auth library
    $auth = new auth();
    $response = $auth->validate($this->authorization);

    if (!$response) $valid = false;

    else $this->auth = $response;

    # is it valid or not?
    if (!$valid){

      if ($return) return false;

      # if we aren't supposed to return...then spit out http 
      http_response_code(401);
      print "Unauthorized";
      exit;
    }

  }
  ################################




  ################################
  # intercept
  ################################
  function intercept(){

    # intercept session login attempts...
    if (strpos($this->path,"authenticate") === 0){
      $auth = new auth();
      $token = $auth->generate($_REQUEST['username'],$_REQUEST['password']);

      if (!$token) {
        http_response_code(403);
        print "Invalid username and password";
      } else {
        print json_encode(array("token" => $token));
      }
    
    # intercept schema requests
    } elseif ($this->config->schema_route && strpos($this->path,"schema") === 0){
      
      # require auth...
      $this->authorize();

      # include the controller...
      include "schema/controller.php";
      $route = explode("/",$this->path);
      $table = $route[1];
      $variables['table'] = $table;

      # get that shcema data!
      $processor = new api_module();
      $processor->_get($variables);
      exit;

    } else {
      return true;
    }

    exit;
  }
  ################################



}
##########################################





##########################################
# api_super - helper functions
##########################################
class api_super {


  ################################
  # redirect
  ################################
  function redirect($location){
    header("Location: " . $location);
    exit;
  }
  ################################



  ################################
  # p
  ################################
  function p($x){
    return $_REQUEST[$x];
  }
  ################################


  ################################
  # date - parse javascript datetime object
  ################################
  function date($y){
    $y = trim(preg_replace("/(T)|(Z)|(\.\d+)|\"/",' ',$y));
    return date("Y-m-d H:i:s", strtotime($y));
  }
  ################################


  ################################
  # respond
  ################################
  function respond($response){
    header("Content-type: application/json");
    ob_start("ob_gzhandler");
    print json_encode($response);
    ob_end_flush();
    exit;
  }
  ################################


  ################################
  # error 
  ################################
  function error($response){
    http_response_code(400);
    header("Content-type: application/json");
    ob_start("ob_gzhandler");
    print json_encode($response);
    ob_end_flush();
    exit;
  }
  ################################



}
##########################################



?>