<?php
namespace Booking\Model;

use xPDO\xPDO;

/**
 * Class Holiday
 *
 * @property string $date
 * @property string $holiday_type
 * @property string $comment
 *
 * @property \Booking\Model\HolidayPrice[] $Prices
 *
 * @package Booking\Model
 */
class Holiday extends \xPDO\Om\xPDOSimpleObject
{
}
