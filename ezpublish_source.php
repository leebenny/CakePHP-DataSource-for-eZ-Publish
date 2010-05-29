<?php
/**
 * Data Source for CMS eZ publish
 *
 * @author      bennylee <lee-huabin@mitsue.co.jp>
 * @copyright   Copyright(C) 2010 Mitsue-Links Co.,Ltd. All rights reserved.
 * @version     0.01
 * @since       2010/05/18
 * 
 **/



Class EzpublishSource extends DataSource {
        var $description = 'Data Source for eZ publish';
        
        public function __construct($config = null, $autoConnect = true)
        {
            parent::__construct($config);

            if ($autoConnect) {
                return $this->connect();
            } else {
                return true;
            }
        }

	public function connect() {
            $config = $this->config;
            $this->connected = false;
            #call for ez's setting
            require WWW_ROOT.DS.'autoload.php';
            if ( file_exists( WWW_ROOT.DS. "config.php" ) )
            {
                require WWW_ROOT.DS."config.php";
            }

            #siteaccess will be called automatically
            $script = eZScript::instance( array( 'description' => ( "Script Load" ),
                                                 'use-session' => false,
                                                 'use-modules' => true,
                                                 'use-extensions' => true ) );
            $script->startup();
            $script->initialize();

            if ($script->isInitialized()) {
                $this->connected = true;
            }
            return $this->connected;
	}
        
	public function listSources() {
            #get all class's Identifier that is designed in ez publish (by array format)
            $classes = eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, false, false);
            foreach ($classes as $class_object) {
                $classes_ids[] = $class_object['identifier'];
            }
            return $classes_ids;
        }

        public function describe(&$model) {
            #get all class's attributes info that is designed in ez publish
            $class_attributes = eZContentClass::fetchAttributes(eZContentClass::classIDByIdentifier( $model->table ),false);
            foreach($class_attributes as $attribute => $setting) {
                $fields[$setting['identifier']] = $setting;
                $fields[$setting['identifier']]['type'] = $setting['data_type_string'];
                $fields[$setting['identifier']]['null'] = $setting['is_required'];
                if($setting['identifier'] === 'id') {
                    $fields[$setting['identifier']]['key'] = 'primary';
                }
                # url will be use to fetch the parent note
                $fields['_ezurl'] = array('type'=>'string','null'=>0);
            }
            return $fields;
        }

	public function read(&$model, $queryData = array()) {
            $config = $this->config;
            #type = children by default
            $queryData['target'] = ife(in_array($queryData['target'],array('self','children')), $queryData['target'], "children");
            $records = array();
            switch($queryData['target']) {
                case 'self':
                    $records = $this->_findSelf($model, $queryData);
                    break;

                case 'children':
                    $records = $this->_findChildren($model, $queryData);
                    break;
            }

            if ($model->findQueryType === 'count') {

                return array(array(array('count' => count($records)))) ;
            }


            return $records;
	}

        protected function _findSelf(&$model, $queryData = array()) {
            $self_node = eZContentObjectTreeNode::fetchByURLPath($queryData['conditions']['_ezurl'],true);
            if( !($self_node instanceOf eZContentObjectTreeNode) ) return;

            $dataMap = $self_node->attribute('object')->attribute('data_map');
            foreach( $dataMap as $id => $attribute )
            {
                $items[$id] = $attribute->attribute('content');
            }
            return array($model->name => $items);
        }

        protected function _findChildren(&$model, $queryData = array()) {
            $parent_node = eZContentObjectTreeNode::fetchByURLPath($queryData['conditions']['_ezurl'],true);
            
            if( $parent_node instanceOf eZContentObjectTreeNode )
            {
            $params = array( 'Depth'                    => ife($queryData['recursive'], $queryData['recursive'], false),
                             'Offset'                   => ife($queryData['offset'], $queryData['offset'], false),
                             //'OnlyTranslated'           => false,
                             'Language'                 => ife($queryData['Language'], $queryData['Language'], false),
                             'Limit'                    => ife($queryData['limit'], $queryData['limit'], false),
                             'SortBy'                   => ife($queryData['order'], $queryData['order'], false),
                             'AttributeFilter'          => ife($queryData['AttributeFilter'], $queryData['AttributeFilter'], false),
                             'ExtendedAttributeFilter'  => ife($queryData['ExtendedAttributeFilter'], $queryData['ExtendedAttributeFilter'], false),
                             'ClassFilterType'          => ife($queryData['ClassFilterType'], $queryData['ClassFilterType'], false),
                             'ClassFilterArray'         => ife($queryData['ClassFilterArray'], $queryData['ClassFilterArray'], false),
                             'GroupBy'                  => ife($queryData['group'], $queryData['group'], false)
                            );
                $c = $parent_node->subTreeCount( $params );
                if( $c )
                {
                    $children = $parent_node->subTree( $params );
                    foreach( $children as $child){
                        $items = array();
                        $dataMap = $child->attribute('object')->attribute('data_map');
                        foreach( $dataMap as $id => $attribute )
                        {
                            $items[$id] = $attribute->attribute('content');
                        }
                        $return[] = array($model->name => $items);
                    }
                    return $return;
                }
            }

            return array();
        }

        public function create(&$model, $fields = array(), $values = array()) {
            $data = array_combine($fields, $values);
            #get parent note id by _ezurl
            $parentNode = eZContentObjectTreeNode::fetchByURLPath($data['_ezurl'],false);

            if(!$parentNode) return false;

            $parentNodeID = $parentNode['node_id'];

            $ini = eZINI::instance();
            $userCreatorID = $ini->variable( 'UserSettings', 'UserCreatorID' );
            $user = eZUser::fetch( $userCreatorID );
            if ( !$user )
            {
               $this->log( "Error!\nCannot get user object by userID = '$userCreatorID'.\n(See site.ini[UserSettings].UserCreatorID)" );
               return false;
            }

            return $this->_eZCreateObject( $model->table, $userCreatorID, $parentNodeID, null, $data );
	}

        public function query(){
            $args = func_get_args();

            switch ($args[0]) {
                case 'eZdel':
                    $objectID = $this->_getObjectIDByeZURL($args[1][0]);
                    return $this->_removeObject($objectID);
                case 'eZupdate':
                    $objectID = $this->_getObjectIDByeZURL($args[1][0]);
                    return $this->_eZUpdateObject($objectID,null,$args[1][1]);
            }

        }


        public function calculate(&$model, $func, $params = array())
        {
            return array('count' => true);
        }
        /* close
        **
        **
        */
        public function close()
        {
            if ($this->connected) {
                $this->connected = false;
            }
        }


        protected function _getObjectIDByeZURL($_ezurl) {
            $node = eZContentObjectTreeNode::fetchByURLPath($_ezurl,false);
            if($node) {
                $objectID = $node['contentobject_id'];
                return $objectID;
            }
            return false;
        }
        /*
        $postVariables = array( 'name' => 'name values',
                                'body' => 'body values' );
        */

        /**
         * eZCreateObject
         *
         * @param string $classIdentifier
         * @param int    $userID
         * @param int    $parentNodeID
         * @param string $targetLanguage
         * @param array  $postVariables
         * @return boolean
         */
        protected function _eZCreateObject( $classIdentifier, $userID, $parentNodeID, $targetLanguage = null, $postVariables )
        {
            /* create new object */
            $eZContentClass = eZContentClass::fetchByIdentifier( $classIdentifier );
            $contentObject = $eZContentClass->instantiate( $userID, 0, false, $targetLanguage );
            /* node assignment */
            $nodeAssignment = eZNodeAssignment::create( array(
                        'contentobject_id' => $contentObject->attribute( 'id' ),
                        'contentobject_version' => 1,
                        'parent_node' => $parentNodeID,
                        'is_main' => 1 ) );
            $nodeAssignment->store();
            
            /* set version status */
            $contentObjectVersion = $contentObject->version( $contentObject->attribute( 'current_version' ) );
            $contentObjectVersion->setAttribute( 'status', eZContentObjectVersion::STATUS_DRAFT );
            $contentObjectVersion->store();


            /* set attributes */
            $contentObjectAttributes = $contentObjectVersion->contentObjectAttributes();
            foreach ( $contentObjectAttributes as $attribute )
            {
                $attributeIdentifier = $attribute->attribute( 'contentclass_attribute_identifier' );
                $value = isset( $postVariables[ $attributeIdentifier ] ) ? $postVariables[ $attributeIdentifier ] : false;
                if( $value != false )
                {
                    $attribute->fromString( $value );
                    $attribute->store();
                }
            }
            /* publish object */
            $operationResult = eZOperationHandler::execute( 'content', 'publish',
                                                            array( 'object_id' => $contentObject->attribute( 'id' ),
                                                                   'version' => $contentObjectVersion->attribute( 'version' ) ) );
            return $operationResult;
        }

        /**
         * eZUpdateObject
         *
         * @param int    $objectID
         * @param string $targetLanguage
         * @param array  $postVariables
         * @return boolean
         */
        protected function _eZUpdateObject( $objectID, $targetLanguage = null, $postVariables )
        {
            /* create vew object version */
            $contentObject = eZContentObject::fetch( $objectID );
            if( !$contentObject instanceOf eZContentObject )
            {
                return false;
            }
            $contentObjectVersion = $contentObject->createNewVersion( false, true, $targetLanguage );

            /* set version status */
            $contentObjectVersion->setAttribute( 'status', eZContentObjectVersion::STATUS_DRAFT );
            $contentObjectVersion->store();

            /* set attributes */
            $contentObjectAttributes = $contentObjectVersion->contentObjectAttributes();
            foreach ( $contentObjectAttributes as $attribute )
            {
                $attributeIdentifier = $attribute->attribute( 'contentclass_attribute_identifier' );
                $value = isset( $postVariables[ $attributeIdentifier ] ) ? $postVariables[ $attributeIdentifier ] : false;
                if( $value != false )
                {
                    $attribute->fromString( $value );
                    $attribute->store();
                }
            }

            /* publish object */
            $operationResult = eZOperationHandler::execute( 'content', 'publish',
                                                            array( 'object_id' => $contentObject->attribute( 'id' ),
                                                                   'version' => $contentObjectVersion->attribute( 'version' ) ) );
            return $operationResult;
        }


        protected function _removeObject( $objectID )
        {
            $contentObject = eZContentObject::fetch( $objectID );
            if( !$contentObject instanceOf eZContentObject )
            {
                return false;
            }
            eZCache::clearAll();
            $contentObject->purge();
        }
/*
the 'fromString' is a new function in eZ Publish 3.9+. The idea is to have a String representation
of all differnet eZ Attribute types.

Here a documentation about how that string should look like for each eZ Attribute type.


ezauthor:
to sparate one author from another '&' char is used, to separate parts of author data
'|' is used. The system escapes '|','&','\' with the '\' char.
example of toString result

Administrator User|sp@ez.no|0&Sergiy|bla@fooo.tt|1&SP|sp@ez.od.ua|2

to make it easy to parse such kind of strings the class ezstringutils is added under
lib/ezutils. It has to functions as a members.
explodeStr( $str, $delimiter = '|' ) and implodeStr( $str, $delimiter = '|' ). The first one
explodes string to an array with delimiter char, the difference from PHP explode/implode is
that these functions do propper escaping/unescaping of all values.


ezbinaryfile:
toString function of this datatype return string of next format:
filepath|original_filename
filepath is to a file so you can copy this file in a place you want,
original_filename is the original  filename of uploaded files. This might be needed for export
since it is not nice to have file name as md5 of something.
if you want to import binary file to the attribute you need to supply it with full path
to the image argument.

ezboolean:
returns and accepts 1 or 0 for true and false relativly.

ezcountry:
returns coma-separated list of selected countries locale strings like for ex.:
rus-RU,eng-GB,nor-NO

ezdate:
returns/accepts unix timestamp of the date.

ezdatetime:
returns/accepts unix timestamp of the date.

ezemail
returns/accepts email address.

ezenum:
not supported

ezfloat
returns/accepts floats.

ezidentifier:
hm.. though import/export is not needed feature for this datatype [to|from]String function
return|accept identifier value

ezimage
returns path to original alias of an image. Accepts full path to the image you want to upload.

ezinisetting
returns accepts value of an inisetting.

ezinteger
just integer value both ways.

ezisbn
ISBN number as a string

ezkeyword
coma separated list of keywords

ezmatrix
uses similar format to ezauthor datatype. The columns are sparated with '|' and rows are separated with '&'

ezmedia
toString function of this datatype return string of next format:
filepath|original_filename
if you want to import media file to the attribute you need to supply it with full path
to the media file.

ezmultioption
The first '&' separated value is the name of multioption set, then each '&' separated string represents
each option in multioption set. This string it self is '|' separated value, consist of inorder:
_name_ of the option and the _id_ of option item which should be selected by default. After these to
values we have option_value and additional price for the option item.


ezmultiprice
The structure of a data handled by this data type is
currency_name_1|value_1|type_of_price_1|currency_name_2|value_2|type_of_price_2|......currency_name_n|value_n|type_of_price_n|
Where currency_name is thre char currency name like EUR,USD and so on,
value is the price in this currency,
and type can be AUTO or CUSTOM dependin on if the price in this currency
has been inserted by user or calculated automaticaly.


ezobjectrelation
ID of related object both ways.

ezobjectrelationlist
'-' separated list of related object ID'd.

ezoption
'|' separated list of option name of the option and then | sparated list of option_item|additional item price values.
ezpackage
Not supported.

ezprice
'|' separated list of price, VAT id, and flag wether VAT is included to the price or not.


ezproductcategory
'|' separated string with product category name and category id, though you can call fromString method with just category
name as a papameter.

ezrangeoption
'|' separated string contains name of the option, start,stop and step values for the option.

ezselection
'|' separated list of selected election item names.

ezstring
just a string

eztext
the dat text from the attribute.

eztime
string with the time of the day like HH:MM in 24h format

ezurl
string containing the url

ezuser
'|' separated string with user login, email, password hash, and password hash type

ezxmltext
raturns valid ez publish xml, and expects the same as input.

*/

}
?>
