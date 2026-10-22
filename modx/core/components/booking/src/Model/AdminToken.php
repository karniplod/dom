<?php
namespace Booking\Model;

use xPDO\xPDO;

/**
 * Class AdminToken
 *
 * @property integer $user_id
 * @property string $label
 * @property string $token_hash
 * @property string $created_at
 * @property string $last_used_at
 * @property string $revoked_at
 *
 * @package Booking\Model
 */
class AdminToken extends \xPDO\Om\xPDOSimpleObject
{
}
