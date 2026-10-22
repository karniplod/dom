<?php
namespace Booking\Processors;

use Booking\Model\AdminToken;
use Booking\Service\BookingError;
use MODX\Revolution\modUser;

/**
 * booking_issue_token — токены администратора для PWA и MAX: op=list | issue | revoke. Только из админки MODX.
 * Токен показывается один раз; в БД — SHA-256. Владелец токена пишется в журнал его действий.
 */
class Token extends Base
{
    public static bool $cmpOnly = true;

    protected function handle(): array
    {
        $b = $this->booking;
        switch ($this->str('op', 20) ?: 'list') {
            case 'issue':
                $label = $this->str('label', 100);
                if ($label === '') {
                    throw BookingError::field('label', 'Подпишите токен: чей телефон');
                }
                $userId = (int)$b->actor()['user_id'];
                if (!$userId) {
                    throw new BookingError('Токен выпускает пользователь MODX', 403);
                }
                $t = $b->access()->issueToken($userId, $label);
                $b->log()->write('token', $t['id'], 'issue', null, ['label' => $label, 'user_id' => $userId]);
                return $t + ['label' => $label];
            case 'revoke':
                $t = $this->modx->getObject(AdminToken::class, (int)$this->getProperty('id'));
                if (!$t) {
                    throw new BookingError('Токен не найден', 404);
                }
                if (!$t->get('revoked_at')) {
                    $t->set('revoked_at', $b->now());
                    $t->save();
                    $b->log()->write('token', (int)$t->get('id'), 'revoke', null, ['label' => $t->get('label')]);
                }
                return ['id' => (int)$t->get('id')];
            default:
                $q = $this->modx->newQuery(AdminToken::class);
                $q->sortby('id', 'DESC');
                $out = [];
                foreach ($this->modx->getIterator(AdminToken::class, $q) as $t) {
                    $u = $this->modx->getObject(modUser::class, $t->get('user_id'));
                    $out[] = $t->get(['id', 'label', 'created_at', 'last_used_at', 'revoked_at']) + ['user' => $u ? $u->get('username') : '—'];
                }
                return ['items' => $out];
        }
    }
}
