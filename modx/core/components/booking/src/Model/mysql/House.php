<?php
namespace Booking\Model\mysql;

use xPDO\xPDO;

class House extends \Booking\Model\House
{

    public static $metaMap = array (
        'package' => 'Booking\\Model',
        'version' => '3.0',
        'table' => 'booking_houses',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'code' => '',
            'name' => '',
            'slug' => '',
            'description' => NULL,
            'address' => '',
            'resource_id' => NULL,
            'capacity_sleep' => 1,
            'capacity_party' => 1,
            'checkin_time' => '15:00:00',
            'checkout_time' => '12:00:00',
            'cleanup_minutes' => 60,
            'addon_bookable_separately' => 0,
            'active' => 1,
            'sort' => 0,
        ),
        'fieldMeta' => 
        array (
            'code' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '20',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'name' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'slug' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'description' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
            ),
            'address' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '255',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'resource_id' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => true,
            ),
            'capacity_sleep' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 1,
            ),
            'capacity_party' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 1,
            ),
            'checkin_time' => 
            array (
                'dbtype' => 'time',
                'phptype' => 'string',
                'null' => false,
                'default' => '15:00:00',
            ),
            'checkout_time' => 
            array (
                'dbtype' => 'time',
                'phptype' => 'string',
                'null' => false,
                'default' => '12:00:00',
            ),
            'cleanup_minutes' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 60,
            ),
            'addon_bookable_separately' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
                'null' => false,
                'default' => 0,
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
            'sort' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
        ),
        'indexes' => 
        array (
            'code' => 
            array (
                'alias' => 'code',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'code' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'slug' => 
            array (
                'alias' => 'slug',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'slug' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
        ),
        'composites' => 
        array (
            'Addons' => 
            array (
                'class' => 'Booking\\Model\\Addon',
                'local' => 'id',
                'foreign' => 'house_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
            'PriceRules' => 
            array (
                'class' => 'Booking\\Model\\PriceRule',
                'local' => 'id',
                'foreign' => 'house_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
        ),
    );

}
