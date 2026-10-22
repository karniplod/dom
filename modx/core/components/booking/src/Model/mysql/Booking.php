<?php
namespace Booking\Model\mysql;

use xPDO\xPDO;

class Booking extends \Booking\Model\Booking
{

    public static $metaMap = array (
        'package' => 'Booking\\Model',
        'version' => '3.0',
        'table' => 'booking_bookings',
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
            'guests_count' => 1,
            'pets_count' => 0,
            'guest_name' => '',
            'guest_phone' => '',
            'guest_comment' => NULL,
            'quote_total' => 0,
            'quote_discount' => 0,
            'quote_lines' => NULL,
            'status' => 'new',
            'cancellation_requested' => 0,
            'cancellation_requested_by' => NULL,
            'cancellation_reason' => NULL,
            'deposit_amount' => NULL,
            'deposit_status' => 'not_set',
            'source' => 'site',
            'legacy_lead_id' => NULL,
            'guest_token_hash' => '',
            'admin_note' => NULL,
            'created_at' => NULL,
            'updated_at' => NULL,
            'updated_by' => '',
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
            'guests_count' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 1,
            ),
            'pets_count' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '3',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'guest_name' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'guest_phone' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '20',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'guest_comment' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
            ),
            'quote_total' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'quote_discount' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'quote_lines' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'json',
                'null' => true,
            ),
            'status' => 
            array (
                'dbtype' => 'enum',
                'precision' => '\'new\',\'confirmed\',\'cancelled\',\'completed\'',
                'phptype' => 'string',
                'null' => false,
                'default' => 'new',
            ),
            'cancellation_requested' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
                'null' => false,
                'default' => 0,
            ),
            'cancellation_requested_by' => 
            array (
                'dbtype' => 'enum',
                'precision' => '\'guest\',\'admin\'',
                'phptype' => 'string',
                'null' => true,
            ),
            'cancellation_reason' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
            ),
            'deposit_amount' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => true,
            ),
            'deposit_status' => 
            array (
                'dbtype' => 'enum',
                'precision' => '\'not_set\',\'pending\',\'paid\',\'returned\'',
                'phptype' => 'string',
                'null' => false,
                'default' => 'not_set',
            ),
            'source' => 
            array (
                'dbtype' => 'enum',
                'precision' => '\'site\',\'pwa\',\'max\',\'admin\',\'migrated\'',
                'phptype' => 'string',
                'null' => false,
                'default' => 'site',
            ),
            'legacy_lead_id' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => true,
            ),
            'guest_token_hash' => 
            array (
                'dbtype' => 'char',
                'precision' => '64',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'admin_note' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
            ),
            'created_at' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
            ),
            'updated_at' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
            ),
            'updated_by' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
        ),
        'indexes' => 
        array (
            'house_status_from' => 
            array (
                'alias' => 'house_status_from',
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
                    'status' => 
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
            'status' => 
            array (
                'alias' => 'status',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'status' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'guest_phone' => 
            array (
                'alias' => 'guest_phone',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'guest_phone' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'legacy_lead_id' => 
            array (
                'alias' => 'legacy_lead_id',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'legacy_lead_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => true,
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
            'Addon' => 
            array (
                'class' => 'Booking\\Model\\Addon',
                'local' => 'addon_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
        ),
    );

}
