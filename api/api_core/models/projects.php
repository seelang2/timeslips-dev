<?php
/*********
 * projects.php - Model file
 * @author: Chris Langtiw
 *
 * @description: 
 * 
 ****/


class Projects extends Model {

	public $tableName = 'projects';

    protected $relationships = array( 
	    //'has' => array(
	    //    'timeslips' => array( 				// Alias to use for this table (use model name if no alias)
	    //        'model'     => 'Timeslips', 		// Name of the model (or model on other side of link table)
	    //        'localKey'  => 'id', 				// Foreign key field name for local model
	    //        'remoteKey' => 'project_id' 		// The foreign key field name for the other model
	    //        //'linkTable' => 'linkTableName' 	// Name of link table to use
	    //    ),
	    //)
	    //'habtm' => array(
	    //    'tags' => array( 					// Alias to use for this table (use model name if no alias)
	    //        'model'     => 'Tags', 			// Name of the model (or model on other side of link table)
	    //        'localKey'  => 'project_id', 	// Foreign key field name for local model
	    //        'remoteKey' => 'tag_id', 		// The foreign key field name for the other model
	    //        'linkTable' => 'projecttags' 	// Name of link table to use
	    //    )
	    //)
    );



}