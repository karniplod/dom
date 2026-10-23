<?php
namespace Booking\Model;

use xPDO\xPDO;

/**
 * Class HolidayPrice
 *
 * @property integer $holiday_id
 * @property integer $house_id
 * @property integer $price сутки Дома в эту дату; 0 — по правилу
 * @property integer|null $addon_price баня, ₽/час в эту дату; null — по типу дня
 *
 * @package Booking\Model
 */
class HolidayPrice extends \xPDO\Om\xPDOObject
{
}
