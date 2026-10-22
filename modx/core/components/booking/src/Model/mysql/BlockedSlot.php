<?php
namespace Booking\Model\mysql;

use xPDO\xPDO;

class BlockedSlot extends \Booking\Model\BlockedSlot
{

    public static $metaMap = array (
        'package' => 'Booking\\Model',
        'version' => '3.0',
        'table' => 'booking_blocked_slots',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'house_id' => NULL,
            'addon_id' => NULL,
            'date_from' => NULL,
            'date_to' => NULL,
            'time_from' => NULL,
            'time_to' => NULL,
            'reason' => '',
            'created_by' => '',
            'created_at' => NULL,
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
            'addon_id' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => true,
            ),
            'date_from' => 
            array (
                'dbtype' => 'date',
                'phptype' => 'string',
                'null' => false,
            ),
            'date_to' => 
            array (
                'dbtype' => 'date',
                'phptype' => 'string',
                'null' => false,
            ),
            'time_from' => 
            array (
                'dbtype' => 'time',
                'phptype' => 'string',
                'null' => true,
            ),
            'time_to' => 
            array (
                'dbtype' => 'time',
                'phptype' => 'string',
                'null' => true,
            ),
            'reason' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '255',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'created_by' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'created_at' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
            ),
        ),
        'indexes' => 
        array (
            'house_from' => 
            array (
                'alias' => 'house_from',
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
                    'date_from' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
        ),
    );

}
