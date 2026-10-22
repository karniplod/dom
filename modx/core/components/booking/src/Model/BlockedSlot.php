<?php
namespace Booking\Model;

use xPDO\xPDO;

/**
 * Class BlockedSlot
 *
 * @property integer $house_id
 * @property integer $addon_id
 * @property string $date_from
 * @property string $date_to
 * @property string $time_from
 * @property string $time_to
 * @property string $reason
 * @property string $created_by
 * @property string $created_at
 *
 * @package Booking\Model
 */
class BlockedSlot extends \xPDO\Om\xPDOSimpleObject
{
}
