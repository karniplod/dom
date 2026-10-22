<?php
namespace Booking\Model\mysql;

use xPDO\xPDO;

class LogEntry extends \Booking\Model\LogEntry
{

    public static $metaMap = array (
        'package' => 'Booking\\Model',
        'version' => '3.0',
        'table' => 'booking_log',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'created_at' => NULL,
            'entity' => '',
            'entity_id' => 0,
            'action' => '',
            'old_value' => NULL,
            'new_value' => NULL,
            'actor' => '',
            'interface' => 'cli',
        ),
        'fieldMeta' => 
        array (
            'created_at' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
            ),
            'entity' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '20',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'entity_id' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'action' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '40',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'old_value' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'json',
                'null' => true,
            ),
            'new_value' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'json',
                'null' => true,
            ),
            'actor' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'interface' => 
            array (
                'dbtype' => 'enum',
                'precision' => '\'cmp\',\'api\',\'pwa\',\'max\',\'site\',\'cli\'',
                'phptype' => 'string',
                'null' => false,
                'default' => 'cli',
            ),
        ),
        'indexes' => 
        array (
            'entity' => 
            array (
                'alias' => 'entity',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'entity' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                    'entity_id' => 
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
