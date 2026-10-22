<?php
namespace Booking\Model\mysql;

use xPDO\xPDO;

class AdminToken extends \Booking\Model\AdminToken
{

    public static $metaMap = array (
        'package' => 'Booking\\Model',
        'version' => '3.0',
        'table' => 'booking_admin_tokens',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'user_id' => 0,
            'label' => '',
            'token_hash' => '',
            'created_at' => NULL,
            'last_used_at' => NULL,
            'revoked_at' => NULL,
        ),
        'fieldMeta' => 
        array (
            'user_id' => 
            array (
                'dbtype' => 'int',
                'precision' => '10',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'label' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'token_hash' => 
            array (
                'dbtype' => 'char',
                'precision' => '64',
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
            'last_used_at' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
            ),
            'revoked_at' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
            ),
        ),
        'indexes' => 
        array (
            'token_hash' => 
            array (
                'alias' => 'token_hash',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'token_hash' => 
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
