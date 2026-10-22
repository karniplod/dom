<?php
namespace Booking\Model\mysql;

use xPDO\xPDO;

class Holiday extends \Booking\Model\Holiday
{

    public static $metaMap = array (
        'package' => 'Booking\\Model',
        'version' => '3.0',
        'table' => 'booking_holidays',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'date' => NULL,
            'holiday_type' => 'holiday_before_dayoff',
            'comment' => '',
        ),
        'fieldMeta' => 
        array (
            'date' => 
            array (
                'dbtype' => 'date',
                'phptype' => 'string',
                'null' => false,
            ),
            'holiday_type' => 
            array (
                'dbtype' => 'enum',
                'precision' => '\'pre_holiday_workday\',\'holiday_before_dayoff\',\'holiday_before_workday\'',
                'phptype' => 'string',
                'null' => false,
                'default' => 'holiday_before_dayoff',
            ),
            'comment' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '255',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
        ),
        'indexes' => 
        array (
            'date' => 
            array (
                'alias' => 'date',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'date' => 
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
            'Prices' => 
            array (
                'class' => 'Booking\\Model\\HolidayPrice',
                'local' => 'id',
                'foreign' => 'holiday_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
        ),
    );

}
