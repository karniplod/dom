<?php
namespace Booking\Model;

use xPDO\xPDO;

/**
 * Class PriceRule
 *
 * @property integer $house_id
 * @property string $day_type
 * @property integer $price
 * @property integer $extra_guest_price
 * @property integer $extra_guest_limit
 * @property integer $pet_price
 * @property integer|null $addon_price баня, ₽/час в этот тип дня; null — базовая цена бани
 *
 * @package Booking\Model
 */
class PriceRule extends \xPDO\Om\xPDOSimpleObject
{
}
