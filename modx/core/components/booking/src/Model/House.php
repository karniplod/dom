<?php
namespace Booking\Model;

use xPDO\xPDO;

/**
 * Class House
 *
 * @property string $code
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property string $address
 * @property integer $resource_id
 * @property integer $capacity_sleep
 * @property integer $capacity_party
 * @property string $checkin_time
 * @property string $checkout_time
 * @property boolean $addon_bookable_separately
 * @property boolean $active
 * @property integer $sort
 *
 * @property \Booking\Model\Addon[] $Addons
 * @property \Booking\Model\PriceRule[] $PriceRules
 *
 * @package Booking\Model
 */
class House extends \xPDO\Om\xPDOSimpleObject
{
}
