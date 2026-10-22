<?php
namespace Booking\Model;

use xPDO\xPDO;

/**
 * Class LogEntry
 *
 * @property string $created_at
 * @property string $entity
 * @property integer $entity_id
 * @property string $action
 * @property array $old_value
 * @property array $new_value
 * @property string $actor
 * @property string $interface
 *
 * @package Booking\Model
 */
class LogEntry extends \xPDO\Om\xPDOSimpleObject
{
}
