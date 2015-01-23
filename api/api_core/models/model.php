<?php
/*********
 * model.php - Model file
 * @author: Chris Langtiw
 *
 * @description: 
 * 
 ****/


/****
  The base Model class that all other models inherit from
  Defines the base methods
 **/
class Model {

	protected $modelName = NULL;
    protected $parentModel = NULL;
    protected $subModel = NULL;
    protected $fieldList = array();
    protected $primaryKey = NULL;
    protected $db = NULL;

    public $tableName = NULL;                       // table name in db
    protected $requestParams = array();				// Request parameter array for this model
    protected $relationships = array();				// holds array of table relationships

    /****
        The relationships get defined using the following structure:

	    protected $relationships = array(
	    	'relation' => array(						// 'has', 'belongsTo', 'HABTM'
		        'alias' => array(                       // Alias to use for this table (use model name if no alias)
		            'model'     => 'modelClass',        // Name of the model (or model on other side of link table)
		            'localKey'  => 'foreignKeyField',   // Foreign key field name for local model
		            'remoteFK'  => 'remoteForeignKey'   // The foreign key field name for the related model
		            'linkTable' => 'linkTableName'      // Name of link table to use
		        )
	        )
	    );

     **/

    /****
      a reference to the database connection object is passed to the constructor and stored locally
      as dependency injection, as are the request parameters.

      Takes the parsed request parameters and instantiates the models required by the request. 
      Request parameters are stored in each model in the chain. These options can be changed or 
      overridden by method calls.
     **/
    function __construct(&$db, $requestParams, &$parentModel = NULL) {
        $this->parentModel = $parentModel;
        $this->db = $db;
        $this->modelName = get_class($this);

        if (empty($this->fieldList)) {
            $this->loadFieldList();
        }

        // find primary key
        foreach($this->fieldList as $field) {
            if ($field['Key'] == 'PRI') {
                $this->primaryKey = $field['Field'];
                break;
            }
        }
        
        // attach request parameters to model
        $this->requestParams = array_shift($requestParams);

        // if there's more in the requestParams array, instantiate the next model and attach
        if (!empty($requestParams)) {
        	$subModelName = array_shift(array_keys($requestParams));
        	$this->subModel = new $subModelName($db, $requestParams, $this);
        }



    } // __construct

    /****
      Test method.
     **/
    public function test($params = array()) {
    	// override request params with passed params (if any)
    	$requestParams = array_merge($this->requestParams, $params);

    	return array((int)microtime(true), 'Test Method called on model '.get_class($this).'.');
    } // test

    /****
      Returns the request parameters array for this model (if any)
     **/
    public function getRequestParams() {
    	return $this->requestParams;
    } // getRequestParams

    /****
      Returns the request parameters array for the entire chain
     **/
    public function getRequestParamsChain() {
    	$requestParams = array();

    	$rootModel = $this->getRootModel();
    	$requestParams[get_class($rootModel)] = $rootModel->requestParams;
    	
    	while ($rootModel->subModel != NULL) {
    		$rootModel = $rootModel->subModel;
    		$requestParams[get_class($rootModel)] = $rootModel->requestParams;
    	}

    	return $requestParams;
    } // getRequestParams

    /****
      Finds the root of a chain of connected models
     **/
    public function getRootModel() {
        $rootModel = $this;
        while ($rootModel->parentModel != NULL) {
            $rootModel = $rootModel->parentModel;
        }

        return $rootModel;
    }

    /****
      Returns array of models attached to this model
     **/
    public function getRelatedModels() {
        $rootModel = $this->getRootModel();
        $modelList = array();
        foreach($rootModel->relatedModels as $modelName => $modelObj) {
            $modelList[] = $modelName;
        }
        return $modelList;
    }

    /****
      Returns a specified model from the array of attached models
     **/
    protected function getModel($modelName) {
        $root = $this->getRootModel();

        return $root->relatedModels[$modelName];
    }

    /****
      Looks up model given an alias (or returns entire list if no alias given)
     **/
    public function findModelAlias($params = NULL) {
        if (count($params) > 1) {
            throw new Exception("Incorrect number of parameters for ".get_class($this)."->getOne");
        }

        if (empty($params)) return $this->relatedModels;

        foreach($this->relatedModels as $model => $aliasList) {
            if (in_array($params[0], $aliasList)) return $model;
        }
    }

    /****
      Loads model table field names array from cache file or creates cache file if it doesn't exist
     **/
    protected function loadFieldList() {
        if (file_exists(CACHE_DIR.DS.get_class($this).'.dat')) {
            $this->fieldList = loadData(CACHE_DIR.DS.get_class($this).'.dat');
            return;
        }

        $results = $this->db->query('DESCRIBE '.$this->tableName);
        $this->fieldList = $results->fetchAll(PDO::FETCH_ASSOC);
        saveData($this->fieldList, CACHE_DIR.DS.get_class($this).'.dat');

    } // loadFieldList

    /****
      Returns dump of fieldList
     **/
    public function getFieldList() {
        if (empty($this->fieldList)) return false;

        return $this->fieldList;

    } // getFieldList

    /****
      Dynamically builds query
      Not currently used but kept for reference
     **/
    protected function buildQuery(&$parentModel, &$query, $parentAlias = NULL) {
        if (empty($parentAlias)) $parentAlias = $parentModel->tableName;

        if (!empty($parentModel->has)) {
            foreach ($parentModel->has as $alias => $relModel) {
                $model = $relModel['model'];
                // create instance of related model and attach to current model
                $parentModel->{$model} = new $model($parentModel->db); 

                $query .= " LEFT JOIN ".$parentModel->{$model}->tableName.
                          " AS ".$alias." ON ".$parentAlias.".".$parentModel->primaryKey." = ".$alias.".".$relModel['fk'];

                $this->{$model}->connectModels($parentModel->{$model}, $query, $alias);        
            }
        }
    }

    /****
      Recursively queries and builds result dataset
     **/
    protected function getRelatedData($parentModel, $id = NULL, $key = 'id', $parentAlias = NULL, $linkTable = NULL, $remoteFK = NULL) {
        if (empty($parentAlias)) $parentAlias = get_class($parentModel);

        // first, get the record specified by $key = $id
        $query = "SELECT * FROM ". $parentModel->tableName ." AS ". $parentAlias;
        if (!empty($linkTable)) $query .= ", $linkTable";
        if (!empty($id)) $query .= " WHERE ".$key." = '". $id ."' ";
        if (!empty($linkTable)) $query .= " AND {$parentModel->primaryKey} = $remoteFK";

        $result = $parentModel->db->query($query);
        if (!$result) throw new Exception("SQL query error. Query: $query");

        $tmp = $result->fetchAll(PDO::FETCH_ASSOC);

        // for each result row:
            // if there are any connected models, for each model:
                // get the records that relate to the row
        foreach ($tmp as $rowKey => $row) {
            // get the $row's id
            $rowID = $row[$parentModel->primaryKey];

            if (!empty($parentModel->relationships['has'])) {
                foreach ($parentModel->relationships['has'] as $alias => $relModel) {
                    $modelName = $relModel['model'];
                    $model = $parentModel->getModel($modelName);

                    // this is a 1-M, so the FK is in the related table, so get the FK name
                    $fk = $relModel['fk'];

                    $tmp[$rowKey][$alias] = $model->getRelatedData(
                        $model,
                        $rowID,
                        $alias.".".$fk,
                        $alias
                    );
               }
            } // if has

            if (!empty($parentModel->relationships['belongsTo'])) {
                foreach ($parentModel->relationships['belongsTo'] as $alias => $relModel) {
                    $modelName = $relModel['model'];
                    $model = $parentModel->getModel($modelName);

                    // this is a M-1, so the FK is in this table, so get the FK name
                    $fk = $relModel['fk'];
                    // now get the related row ID from the row's FK
                    $fkID = $row[$fk];

                    $tmp[$rowKey][$alias] = $model->getRelatedData(
                        $model,
                        $fkID,
                        $alias.".".$model->primaryKey,
                        $alias
                    );
               }
            } // if belongsTo

            if (!empty($parentModel->relationships['hasAndBelongsToMany'])) {
                foreach ($parentModel->relationships['hasAndBelongsToMany'] as $alias => $relModel) {
                    $modelName = $relModel['model'];
                    $model = $parentModel->getModel($modelName);

                    // this is a 1-M<->M-1, so the FK is in the link table, so get the FK name
                    $fk = $relModel['fk'];

                    $tmp[$rowKey][$alias] = $model->getRelatedData(
                        $model,
                        $rowID,
                        $relModel['linkTable'].".".$fk,
                        $alias,
                        $relModel['linkTable'],
                        $relModel['linkTable'].".".$relModel['remoteFK']
                    );
               }
            } // if hasAndBelongsToMany


        } // foreach $tmp

        return $tmp;

    } // getRelatedData

    /****
      -
     **/
    public function getAll($params = NULL) {
        
        $data[get_class($this)] = $this->getRelatedData($this);

        return $data;

    } // getAll

    /****
      -
     **/
    public function getOne($params = NULL) {

        if (count($params) != 1) {
            throw new Exception("Incorrect number of parameters for ".get_class($this)."->getOne");
        }

        $data[get_class($this)] = $this->getRelatedData($this, $params[0]);

        return $data;

    } // getOne

    /****
      -
     **/
    public function count($params = NULL) {

    } // count

	/***
	   The idea behind building the queries is to recurse through the model chain, constructing
	   an array in the following structure:

	   query: {
		   fields: [ field1, field2, ... ],
		   from: origin_table,
		   joins: [
		      { table: relationship },
		      ...
		   ],
		   where: [ clause1, clause2, ... ],
		   order: [
		      { field: direction },
		      ...
		   ],
		   limit: [ range, offset ]
	   }

	   Using this structure, the components of the query can be added in any order, and later
	   parsed into a proper query string
	 */
	public function getQueryComponents($queryArray = array()) {

		if (empty($queryArray)) $queryArray = array();

		if (!isset($queryArray['fields'])) $queryArray['fields'] = array();
		if (!isset($queryArray['joins'])) $queryArray['joins'] = array();
		if (!isset($queryArray['where'])) $queryArray['where'] = array();
		if (!isset($queryArray['order'])) $queryArray['order'] = array();
		if (!isset($queryArray['limit'])) $queryArray['limit'] = array();

		foreach ($this->getFieldList() as $field) {
			array_push($queryArray['fields'], $this->tableName.'.'.$field['Field'].' AS '."'".$this->tableName.'.'.$field['Field']."'");
		}

		if ($this->requestParams['id'] != null) {
			array_push(
				$queryArray['where'], 
				$this->tableName.'.'.$this->primaryKey.' = '.$this->requestParams['id']
			);
		}
		
		if ($this->subModel != null) {
			/*
			array_push(
				$queryArray['joins'], 
			    array($this->subModel->tableName => $this->relationships[$this->subModel->tableName])
			);
			*/ 
		}

		if ($this->subModel != null) {
			return $this->subModel->getQueryComponents($queryArray);
		} else {
			$queryArray['from'] = $this->tableName;
			return $queryArray;
		}
	} // getQueryComponents

	public function queryTest() {

		return $this->getQueryComponents();

	} // queryTest


   /****
     REST API methods
     ----------------
     The following methods correspond to the HTTP methods supported by the API.
     Each of these methods should set a proper HTTP 1.1 status code as part of the response.
     See http://tools.ietf.org/html/rfc7231
    **/

    /****
      GET - Retrieve either a resource collection or an individual resource if a key is given

      Status Codes 		Condition
      200 OK 			Successful request
      404 Not Found 	When query returns an empty set
      400 Bad Request 	Improper parameters sent or query error

     **/
    public function get($params = array()) {
    	// override request params with passed params (if any)
    	$requestParams = array_merge($this->requestParams, $params);

    	return array((int)microtime(true), 'GET Method called.');
    } // get

    /****
      HEAD - Retrieve either a resource collection or an individual resource if a key is given
      without the representation data (schema and headers/links only)

      Status Codes 		Condition
      200 OK 			Successful request

     **/
    public function head($params = array()) {
    	// override request params with passed params (if any)
    	$requestParams = array_merge($this->requestParams, $params);

    	return array((int)microtime(true), 'HEAD Method called.');
    } // head

    /****
      POST - Create a new resource in a given collection with complete data
     **/
    public function post($params = array()) {
    	// override request params with passed params (if any)
    	$requestParams = array_merge($this->requestParams, $params);

    	return array((int)microtime(true), 'POST Method called.');
    } // post

    /****
      PUT - Update a resource in a collection with a given id with complete data
     **/
    public function put($params = array()) {
    	// override request params with passed params (if any)
    	$requestParams = array_merge($this->requestParams, $params);

    	return array((int)microtime(true), 'PUT Method called.');
    } // put

    /****
      PATCH - Update a resource in a collection with a given id with partial data
     **/
    public function patch($params = array()) {
    	// override request params with passed params (if any)
    	$requestParams = array_merge($this->requestParams, $params);

    	return array((int)microtime(true), 'PATCH Method called.');
    } // patch

    /****
      DELETE - Delete a resource in a collection with a given id
     **/
    public function delete($params = array()) {
    	// override request params with passed params (if any)
    	$requestParams = array_merge($this->requestParams, $params);

    	return array((int)microtime(true), 'DELETE Method called.');
    } // delete

   

} // class Model

