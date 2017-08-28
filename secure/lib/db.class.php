<?php

    # db.php is a database abstraction layer for mysql using the mysqli driver

    # a class that will return a singleton connection to the DB
    class db {

        var $conn;
        var $log_level;
        var $log;

        function __construct($host, $username, $password, $db, $log_level = '', $log_dir = "dblogs") {

            if ($log_level != ''){
               mkdir($log_dir);
            }

            $this->log_level = $log_level;
            $this->log = $log_dir . "/db.log";

            $conn = mysqli_connect($host, $username, $password, $db);

            if (!$conn) {
                throw new Exception('Database connection failed');
            } else {
                $this->conn = $conn;
                # we will store it in an obscure global variable
                $GLOBALS['db_dbconn'] = $this;
            }
            return true;
        }

        function _log($level, $msg){

            if (($this->log_level == 'ERROR' && $level == 'ERR')
                || ($this->log_level == 'LOG' && ($level == 'LOG' || $level == 'ERR')
                || ($this->log_level == 'VERBOSE'))
            ){
                $fw = fopen($this->log,'a');
                fwrite($fw,"[ " . date("Y-m-d H:i:s") . " ] $msg\n");
                fclose($fw);
            }
        }
    }

    # the table level class
    class dbo {
        # store the connection
        var $db_conn;

        # store errors
        var $db_err;

        # store the table name
        var $db_table;

        # store query results
        var $db_results;

        # store table fields
        var $db_fields;

        # store the query result count
        var $db_count;

        # store db Primary Keys
        var $db_pk;

        # store the nonpk fields
        var $db_npk;

        # store the whole row object on select
        var $row;

        # a query limit
        var $db_limit;

        # a query order by
        var $db_order;

        # a query group by
        var $db_group;

        # store where statements
        var $db_whereStatements;

        # store the last used sql
        var $sql;


        ###################################################
        # __contruct
        ###################################################
        function __construct($table = '', $id = false) {
            $this->db = $GLOBALS['db_dbconn'];
            $this->db_conn = $GLOBALS['db_dbconn']->conn;
            $this->db_table = $table;
            $this->db_whereStatements = array();

            if ($id !== false) {

                if (!$this->get_fields())
                    return false;

                $this->id = $id;
                $this->find(true);
            }
        }
        ###################################################


        ###################################################
        # query
        ###################################################
        public function query($query) {
            $this->sql = $query;

            $this->db->_log('VER',$query);

            $results = mysqli_query($this->db_conn, $query);
            $err = mysqli_error($this->db_conn);
            if ($err != '') {
                $this->db->_log('LOG', $query);
                $this->db->_log('ERR',$err);
                $this->err = $err;
                return false;
            } else {
                $this->db_results = $results;
            }
            return true;
        }
        ###################################################


        ###################################################
        # find
        ###################################################
        public function find($fetch = false) {

            if (!count($this->db_fields) && !$this->get_fields())
                return false;

            # build the query
            $sql = "select * from `$this->db_table` ";

            # build the where statement
            $where = array();
            foreach ($this->db_fields AS $field) {
                if (isset($this->$field)) {
                    if ($this->$field == 'NULL') {
                        $where[] = " `$field` is NULL ";
                    } else {
                        $value = mysqli_real_escape_string($this->db_conn, $this->$field);
                          $where[] = " `$field` = '$value' ";
                    }
                }
            }

            $where = array_merge($where, $this->db_whereStatements);

            if (count($where) > 0) {
                $where_stm = " WHERE (";
                $where_stm .= implode(') AND (', $where);

                $sql .= $where_stm . ")";
            }

            if ($this->db_group != '')
                $sql .= " GROUP BY $this->db_group";

            if ($this->db_order != '')
                $sql .= " ORDER BY $this->db_order";

            if ($this->db_limit != '')
                $sql .= " LIMIT $this->db_limit";

            $this->sql = $sql;

            $this->db->_log('VER',$sql);

            $this->db_results = mysqli_query($this->db_conn, $sql);

            $err = mysqli_error($this->db_conn);
            if ($err != '') {
                $this->db->_log('LOG',$sql);
                $this->db->_log('ERR', $err);
                $this->err = $err;
            }

            if (!mysqli_num_rows($this->db_results))
              return false;

            if ($fetch)
                $this->fetch();

            return true;
        }
        ###################################################


        ###################################################
        # fetch
        ###################################################
        public function fetch() {

            if ($this->db_results === null || !intval(mysqli_num_rows($this->db_results)))
                return false;

            $object = mysqli_fetch_object($this->db_results);
            $this->row = $object;
            if (!$object) {
                return false;
            }

            foreach ($object AS $key => $value) {
                $this->$key = $value;
            }
            return true;
        }
        ###################################################


        ###################################################
        # update
        ###################################################
        public function update() {

            if (!count($this->db_fields) && !$this->get_fields())
                return false;

            # in order to update...we need the PK of the record...
            foreach ($this->db_pk as $key) {
                if (!isset($this->$key) || $this->$key == '') {
                    $this->db_err = 'Primary keys must be set in order to update';
                    $this->db->_log("ERR", "DBO: $this->db_err");
                    return false;
                }
            }

            $sql = "UPDATE `$this->db_table` SET ";

            $update_sets = array();
            # iterate over table non pk keys
            foreach ($this->db_npk as $npk) {
                if (isset($this->$npk) || $this->$npk === NULL) {
                    # if it is set to NULL... special case...
                    if ($this->$npk === NULL) {
                        $update_sets[] = " `$npk` = NULL ";
                    } else {
                        # escape the string and add it to the update set
                        $value = mysqli_real_escape_string($this->db_conn, $this->$npk);
                        if (strstr($this->field_types[$npk],'int') || strstr($this->field_types[$npk],'float')){
                          if ($value == '') $value = 0;
                          $update_sets[] = " `$npk` = $value ";
                        }
                        else
                          $update_sets[] = " `$npk` = '$value'";
                    }
                }
            }

            # add the where condition
            $where_cond = array();
            foreach ($this->db_pk as $pk) {
                $value = mysqli_real_escape_string($this->db_conn, $this->$pk);
                if (strstr($this->field_types[$pk],'int') || strstr($this->field_types[$pk],'float')){
                  if ($value == '') $value = 0;
                  $where_cond[] = " `$pk` = $value ";
                }
                else
                  $where_cond[] = " `$pk` = '$value' ";
            }

            # if we have nothing to update...return false...
            if (count($update_sets) < 1) {
                $this->db_err = 'Update failed. There are no new values to update.';
                $this->db->_log("ERR","DBO: $this->db_err");
                return false;
            }

            # finish building the SQL
            $sql .= implode(" , ", $update_sets);

            $sql .= " WHERE " . implode(" , ", $where_cond);

            $this->db->_log("VER",$sql);

            $this->sql = $sql;

            $this->db_results = mysqli_query($this->db_conn, $sql);

            $err = mysqli_error($this->db_conn);
            if ($err != '') {
                $this->db->_log("LOG",$sql);
                $this->db->_log('ERR', $err);
                $this->err = $err;
                return false;
            }

            $this->find(true);
            return true;
        }
        ###################################################


        ###################################################
        # insert
        ###################################################
        public function insert() {
            if (!count($this->db_fields) && !$this->get_fields())
                return false;

            $sql = "INSERT INTO `$this->db_table` ";

            $insert_sets = array();
            # iterate over table non pk keys
            foreach ($this->db_fields as $field) {
                if (isset($this->$field)) {
                    # if it is set to NULL... special case...
                    if ($this->$field === NULL) {
                        $insert_sets[] = " `$field` = NULL ";
                    } else {
                        # escape the string and add it to the update set
                        $value = mysqli_real_escape_string($this->db_conn, $this->$field );
                        if (strstr($this->field_types[$field],'int') || strstr($this->field_types[$field],'float')){
                          if ($value == '') $value = 0;
                          $insert_sets[] = " `$field` = $value ";
                        }
                        else
                        $insert_sets[] = " `$field` = '$value'";
                    }
                }
            }

            foreach ($this->db_pk as $pk) {
                if (!isset($this->$pk)) {
                    $insert_sets[] = " `$pk` =NULL ";
                }
            }

            # if we have nothing to update...return false...
            if (count($insert_sets) > 0) {
                $sql .= " SET " . implode(" , ", $insert_sets);
            }

            $this->db->_log("VER",$sql);

            $this->sql = $sql;

            $result = mysqli_query($this->db_conn, $sql);

            $err = mysqli_error($this->db_conn);

            if ($err != ''){
                $this->db->_log("LOG",$sql);
                $this->db->_log("ERR",$err);
            }

            if ($err != '') {
                $this->err = $err;
                return false;
            } else {
                $this->id = mysqli_insert_id($this->db_conn);
                $this->find(true);
                return true;
            }

        }
        ###################################################


        ###################################################
        # delete
        ###################################################
        public function delete() {

            if (!count($this->db_fields) && !$this->get_fields())
                return false;

            $sql = "DELETE FROM `$this->db_table` ";

            # build the where statement
            $where = array();
            foreach ($this->db_fields AS $field) {
                if (isset($this->$field)) {
                    if ($this->$field === NULL) {
                        $where[] = " `$field` = NULL ";
                    } else {
                        $value = mysqli_real_escape_string($this->db_conn, $this->$field);
                        if (strstr($this->field_types[$field],'int') || strstr($this->field_types[$field],'float')){
                          if ($value == '') $value = 0;
                          $where[] = " `$field` = $value ";
                        }
                        else
                          $where[] = " `$field` = '$value' ";
                    }
                }
            }

            $where = array_merge($where, $this->db_whereStatements);

            if (count($where) > 0) {
                $where_stm = " WHERE (";
                $where_stm .= implode(') AND (', $where);

                $sql .= $where_stm . ")";
            }

            $this->db->_log("VER",$sql);

            $this->sql = $sql;
            mysqli_query($this->db_conn, $sql);

            $err = mysqli_error($this->db_conn);
            if ($err != '') {
                $this->db->_log("LOG",$sql);
                $this->db->_log("ERR",$err);
                $this->err = $err;
                return false;
            }

            return true;
        }
        ###################################################


        ###################################################
        # count
        ###################################################
        public function count(){
            return $this->db_results->num_rows;
        }
        ###################################################


        ###################################################
        # orderBy
        ###################################################
        public function orderBy($order_string) {
            $this->db_order = $order_string;
        }
        ###################################################


        ###################################################
        # groupBy
        ###################################################
        public function groupBy($group_string) {
            $this->db_group = $group_string;
        }
        ###################################################


        ###################################################
        # limit
        ###################################################
        public function limit($limit) {
            $this->db_limit = $limit;
        }
        ###################################################


        ###################################################
        # escape
        ###################################################
        public function escape($string) {
            return mysqli_real_escape_string($this->db_conn,$string);
        }
        ###################################################


        ###################################################
        # whereAdd
        ###################################################
        public function whereAdd($statement) {
            $this->db_whereStatements[] = $statement;
        }
        ###################################################


        ###################################################
        # get_fields()
        ###################################################
        public function get_fields() {

            $this->db_table = str_replace('`','\`',$this->db_table);
            $this->db_table = mysqli_real_escape_string($this->db_conn,$this->db_table);

            $sql = "DESCRIBE `$this->db_table`";
            $this->db->_log("VER",$sql);

            $result = mysqli_query($this->db_conn,$sql );
            $err = mysqli_error($this->db_conn);
            if ($err != '') {
                $this->db->_log("LOG",$sql);
                $this->db->_log("ERR",$err);
                $this->db_err = $err;
                return false;
            }
            while ($field = mysqli_fetch_object($result)) {
                # get the table fields
                $this->db_fields[] = $field->Field;
                $this->field_types[$field->Field] = $field->Type;

                # get the primary keys
                if ($field->Key == 'PRI') {
                    $this->db_pk[] = $field->Field;
                } else {
                    $this->db_npk[] = $field->Field;
                }
            }
            return true;
        }
        ###################################################


        ###################################################
        # get_schema()
        ###################################################
        public function get_schema() {

            $this->db_table = str_replace('`','\`',$this->db_table);
            $this->db_table = mysqli_real_escape_string($this->db_conn,$this->db_table);

            $sql = "DESCRIBE `$this->db_table`";
            $this->db->_log("VER",$sql);

            $result = mysqli_query($this->db_conn,$sql );
            $err = mysqli_error($this->db_conn);
            if ($err != '') {
                $this->db->_log("LOG",$sql);
                $this->db->_log("ERR",$err);
                $this->db_err = $err;
                return false;
            }

            $res = [];
            while ($field = mysqli_fetch_object($result))
                $res[] = $field;

            return $res;
        }
        ###################################################

    }
