<?php
namespace Booking\Model\mysql;

use xPDO\xPDO;

class Addon extends \Booking\Model\Addon
{

    public static $metaMap = array (
        'package' => 'Booking\\Model',
        'version' => '3.0',
        'table' => 'booking_addons',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'house_id' => NULL,
            'name' => 'Баня',
            'price_per_hour' => NULL,
            'min_hours' => 2,
            'buffer_minutes' => 30,
            'active' => 1,
            'rates' => NULL,
            'guest_limit' => 0,
            'extra_guest_price' => 0,
            'extra_guest_per' => 'hour',
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
            'name' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => 'Баня',
            ),
            'price_per_hour' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => true,
            ),
            'min_hours' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '3',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 2,
            ),
            'buffer_minutes' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 30,
            ),
            'active' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
                'null' => false,
                'default' => 1,
            ),
            'rates' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'json',
                'null' => true,
            ),
            'guest_limit' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
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
                'default' => 0,
            ),
            'extra_guest_per' => 
            array (
                'dbtype' => 'enum',
                'precision' => '\'hour\',\'booking\'',
                'phptype' => 'string',
                'null' => false,
                'default' => 'hour',
            ),
        ),
        'indexes' => 
        array (
            'house_id' => 
            array (
                'alias' => 'house_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'house_id' => 
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
