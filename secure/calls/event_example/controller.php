<?php
  
class api_module extends api_super {

  function __construct(){

    # authorization requirements
    $this->_get_auth = false;

  }

  function _get($request){

    if (isset($request->pathParams->id)) {
      $event = new dbo('event',intval($request->pathParams->id));
      $this->respond($event->row);
    }

    $event = new dbo('event');
    $event->whereAdd('end_date > now()');
    $event->orderBy('start_date desc');
    $event->find();

    $events = [];
    while ($event->fetch()) $events[] = $event->row;

    $this->respond($events);
  }

  function _post(){
    $event = new dbo('event');
    $event->title = $this->p('title');
    $event->description = $this->p('description');
    $event->user = $this->uid;
    if (!$event->insert()) $this->error($event->err);

    $this->respond($event->row);
  }

  function _put(){
    $event = new dbo('event',intval($this->p('id')));
    $event->title = $this->p('title');
    $event->description = $this->p('description');
    $event->user = $this->uid;
    $event->start_date = $this->date($this->p('start_date'));
    $event->end_date = $this->date($this->p('end_date'));
    if (!$event->update()) $this->error($event->err);

    $this->respond($event->row);
  }

  function _delete($request){
    $event = new dbo('event',intval($request->pathParams->id));
    $event->delete();
  }

}

?>