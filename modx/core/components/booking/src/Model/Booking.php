<?php
namespace Booking\Model;

use xPDO\xPDO;

/**
 * Class Booking
 *
 * @property integer $house_id
 * @property integer $addon_id
 * @property string $date_from
 * @property string $date_to
 * @property string $time_from
 * @property string $time_to
 * @property integer $guests_count
 * @property integer $pets_count
 * @property string $guest_name
 * @property string $guest_phone
 * @property string $guest_comment
 * @property integer $quote_total
 * @property integer $quote_discount
 * @property array $quote_lines
 * @property string $status
 * @property boolean $cancellation_requested
 * @property string $cancellation_requested_by
 * @property string $cancellation_reason
 * @property integer $deposit_amount
 * @property string $deposit_status
 * @property string $source
 * @property integer $legacy_lead_id
 * @property string $guest_token_hash
 * @property string $admin_note
 * @property string $created_at
 * @property string $updated_at
 * @property string $updated_by
 *
 * @package Booking\Model
 */
class Booking extends \xPDO\Om\xPDOSimpleObject
{
}
