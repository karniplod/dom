<?php
namespace Booking\Model;

use xPDO\xPDO;

/**
 * Class Addon
 *
 * @property integer $house_id
 * @property string $name
 * @property integer $price_per_hour
 * @property integer $min_hours
 * @property integer $buffer_minutes
 * @property boolean $active
 *
 * @package Booking\Model
 */
class Addon extends \xPDO\Om\xPDOSimpleObject
{
}
