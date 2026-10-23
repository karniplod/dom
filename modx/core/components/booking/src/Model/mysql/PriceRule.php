<?php
namespace Booking\Model\mysql;

use xPDO\xPDO;

class PriceRule extends \Booking\Model\PriceRule
{

    public static $metaMap = array (
        'package' => 'Booking\\Model',
        'version' => '3.0',
        'table' => 'booking_price_rules',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'house_id' => NULL,
            'day_type' => 'weekday',
            'price' => 0,
            'extra_guest_price' => 500,
            'extra_guest_limit' => 10,
            'pet_price' => 1000,
            'addon_price' => NULL,
        ),
        'fieldMeta' => 
        array (
            'house_id' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
            ),
            'day_type' => 
            array (
                'dbtype' => 'enum',
                'precision' => '\'weekday\',\'friday\',\'saturday\',\'sunday\'',
                'phptype' => 'string',
                'null' => false,
                'default' => 'weekday',
            ),
            'price' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'extra_guest_price' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 500,
            ),
            'extra_guest_limit' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 10,
            ),
            'pet_price' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 1000,
            ),
            'addon_price' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => true,
            ),
        ),
        'indexes' => 
        array (
            'house_day' => 
            array (
                'alias' => 'house_day',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'house_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                    'day_type' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
        ),
        'aggregates' => 
        array (
            'House' => 
            array (
                'class' => 'Booking\\Model\\House',
                'local' => 'house_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
        ),
    );

}
