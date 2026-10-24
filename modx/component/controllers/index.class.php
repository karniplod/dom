<?php
/**
 * Страница «Заявки» в админке MODX (меню «Пакеты» → «Заявки»): список заявок с сайта, статусы, заметки.
 * Предоплата здесь не учитывается — она вне системы заявок (конституция, ст. 2.1).
 * Подтверждённая заявка занимает ночи дома — вторую заявку на те же ночи подтвердить нельзя.
 */
require_once dirname(__DIR__) . '/Bania.php';

class BaniaIndexManagerController extends \MODX\Revolution\modExtraManagerController
{
    public function getPageTitle() { return 'Заявки'; }
    // «Заявки» сайта — с именами и телефонами гостей: только у администраторов и «Менеджеров сайта» (право bn_site)
    public function checkPermissions() { return $this->modx->hasPermission('frames') && $this->modx->hasPermission('bn_site'); }
    public function getTemplateFile() { return ''; }
    public function getLanguageTopics() { return []; }

    public function process(array $scriptProperties = [])
    {
        $modx = $this->modx;
        Bania::init($modx);
        Bania::ensureTables();
        $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $base = MODX_MANAGER_URL . '?a=index&namespace=bania';
        $token = $modx->user->getUserToken('mgr');

        // сохранить изменения строки
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $msg = '';
            if (!hash_equals((string)$token, (string)($_POST['token'] ?? ''))) $msg = 'Сессия устарела, обновите страницу';
            else {
                $err = Bania::setStatus((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''), (string)($_POST['note'] ?? ''));
                $msg = $err === '' ? 'Заявка №' . (int)$_POST['id'] . ' сохранена' : 'Заявка №' . (int)$_POST['id'] . ': ' . $err;
            }
            $back = (string)($_POST['back'] ?? '');
            $modx->sendRedirect($base . ($back !== '' ? '&' . $back : '') . '&msg=' . rawurlencode($msg));
            return '';
        }

        // фильтры
        $status = (string)($_GET['status'] ?? '');
        $house = (int)($_GET['house'] ?? 0);
        $q = trim((string)($_GET['q'] ?? ''));
        $where = ['1=1']; $args = [];
        if (isset(Bania::STATUSES[$status])) { $where[] = 'status = ?'; $args[] = $status; }
        if ($house) { $where[] = 'house_id = ?'; $args[] = $house; }
        if ($q !== '') {   // поиск по имени, по цифрам телефона (если они есть в запросе) и по номеру
            $digits = preg_replace('~\D~', '', $q);
            $where[] = '(name LIKE ?' . ($digits !== '' ? ' OR phone LIKE ? OR id = ?' : '') . ')';
            $args[] = "%$q%";
            if ($digits !== '') { $args[] = "%$digits%"; $args[] = (int)$digits; }
        }
        $sql = 'SELECT * FROM ' . Bania::table('leads') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 500';
        $st = $modx->prepare($sql); $st->execute($args);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($_GET['export'])) {   // выгрузка в CSV для Excel
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="zayavki-' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['№', 'Создана', 'Статус', 'Дом', 'Заезд', 'Выезд', 'Суток', 'Гостей', 'Примерно, ₽', 'Имя', 'Телефон', 'Связь', 'Повод', 'Комментарий', 'Заметка'], ';');
            // ячейка с «= + - @» в начале — для Excel формула: имя гостя «=HYPERLINK(…)» выполнилось бы при открытии файла
            $cell = function ($v) { $v = (string)$v; return preg_match('~^\s*[=+\-@]|^[\t\r]~u', $v) ? "'" . $v : $v; };   // и с пробелами, табуляцией, переводом строки впереди
            foreach ($rows as $r) fputcsv($out, array_map($cell, [$r['id'], $r['created_at'], Bania::STATUSES[$r['status']] ?? $r['status'], $r['house_name'], $r['checkin'], $r['checkout'], $r['nights'], $r['guests'],
                                                 $r['estimate'], $r['name'], $r['phone'], $r['contact'], $r['occasion'], $r['comment'], $r['note']]), ';');
            fclose($out);
            exit;
        }

        $counts = [];
        foreach ($modx->query('SELECT status, COUNT(*) n FROM ' . Bania::table('leads') . ' GROUP BY status') ?: [] as $c) $counts[$c['status']] = (int)$c['n'];
        $back = http_build_query(array_filter(['status' => $status, 'house' => $house ?: '', 'q' => $q]));
        $houses = Bania::houses();
        $colors = ['new' => '#b3261e', 'contacted' => '#a35b00', 'confirmed' => '#1e7d45', 'done' => '#5b6b7a', 'declined' => '#8a7a70'];
        $occ = ['birthday' => 'день рождения', 'corporate' => 'корпоратив', 'friends' => 'друзья', 'family' => 'семья', 'other' => 'другое'];

        $html = '<div id="bania-leads" style="padding:24px 28px;font:14px/1.5 system-ui,Segoe UI,sans-serif;color:#2b1a14;max-width:1400px">';
        $html .= '<h2 style="font-size:24px;margin:0 0 6px">Заявки с сайта</h2><p style="margin:0 0 16px;color:#6a5243">Новые приходят ещё и на почту из «Конфигурации». «Подтверждена» занимает ночи дома: вторую заявку на те же ночи подтвердить не получится.</p>';
        if (!empty($_GET['msg'])) $html .= '<p style="padding:10px 14px;border-radius:6px;background:' . (strpos($_GET['msg'], ':') !== false ? '#fdecea' : '#e7f5ec') . ';margin:0 0 14px">' . $e($_GET['msg']) . '</p>';
        // вкладки статусов
        $html .= '<p style="margin:0 0 12px">';
        $tab = function ($s, $label) use ($base, $status, $house, $q, $e) {
            $on = $s === $status;
            return '<a href="' . $e($base . '&' . http_build_query(array_filter(['status' => $s, 'house' => $house ?: '', 'q' => $q]))) . '" style="display:inline-block;margin:0 6px 6px 0;padding:6px 12px;border-radius:999px;text-decoration:none;'
                . ($on ? 'background:#2b1a14;color:#fff' : 'background:#f1e7da;color:#2b1a14') . '">' . $label . '</a>';
        };
        $html .= $tab('', 'Все (' . array_sum($counts) . ')');
        foreach (Bania::STATUSES as $k => $v) $html .= $tab($k, $v . ' (' . ($counts[$k] ?? 0) . ')');
        $html .= '</p>';
        // поиск
        $html .= '<form method="get" action="' . $e(MODX_MANAGER_URL) . '" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px"><input type="hidden" name="a" value="index"><input type="hidden" name="namespace" value="bania">'
            . ($status ? '<input type="hidden" name="status" value="' . $e($status) . '">' : '')
            . '<select name="house" style="padding:6px"><option value="">Все дома</option>';
        foreach ($houses as $h) $html .= '<option value="' . $h['id'] . '"' . ($house === $h['id'] ? ' selected' : '') . '>' . $e($h['name']) . '</option>';
        $html .= '</select><input name="q" value="' . $e($q) . '" placeholder="Имя, телефон или №" style="padding:6px;min-width:220px"><button style="padding:6px 14px">Найти</button>'
            . '<a href="' . $e($base . '&' . $back . '&export=1') . '" style="align-self:center;margin-left:8px">Скачать CSV</a></form>';

        if (!$rows) return $html . '<p>Заявок нет.</p></div>';
        $html .= '<table style="width:100%;border-collapse:collapse;background:#fff"><thead><tr style="text-align:left;background:#f1e7da">'
            . '<th style="padding:8px">№ / когда</th><th style="padding:8px">Дом и даты</th><th style="padding:8px">Гость</th><th style="padding:8px">Комментарий</th><th style="padding:8px;width:340px">Статус и заметка</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $dates = $r['checkin'] ? date('d.m.Y', strtotime($r['checkin'])) . ' – ' . ($r['checkout'] ? date('d.m.Y', strtotime($r['checkout'])) : '?') : 'даты не указаны';
            $sel = '';
            foreach (Bania::STATUSES as $k => $v) $sel .= '<option value="' . $k . '"' . ($r['status'] === $k ? ' selected' : '') . '>' . $v . '</option>';
            $html .= '<tr style="border-top:1px solid #e3d5c4;vertical-align:top">'
                . '<td style="padding:8px"><b>№' . (int)$r['id'] . '</b><br><span style="color:#6a5243">' . $e(date('d.m.Y H:i', strtotime($r['created_at']))) . '</span><br>'
                . '<span style="display:inline-block;margin-top:4px;padding:1px 8px;border-radius:999px;color:#fff;background:' . ($colors[$r['status']] ?? '#777') . '">' . $e(Bania::STATUSES[$r['status']] ?? $r['status']) . '</span></td>'
                . '<td style="padding:8px"><b>' . $e($r['house_name']) . '</b><br>' . $e($dates) . ($r['nights'] ? ', ' . (int)$r['nights'] . ' сут.' : '') . '<br>'
                . ($r['guests'] ? (int)$r['guests'] . ' гостей' : 'гостей не указано') . ($r['estimate'] ? ' · ≈ ' . $e(Bania::money($r['estimate'])) : '') . ($r['occasion'] ? '<br>' . $e($occ[$r['occasion']] ?? $r['occasion']) : '') . '</td>'
                . '<td style="padding:8px"><b>' . $e($r['name']) . '</b><br><a href="tel:' . $e($r['phone']) . '">' . $e($r['phone']) . '</a><br>' . $e($r['contact']) . ($r['sms_consent'] ? '<br><span style="color:#1e7d45">SMS можно</span>' : '') . '</td>'
                . '<td style="padding:8px;max-width:280px;white-space:pre-wrap">' . $e($r['comment']) . '</td>'
                . '<td style="padding:8px"><form method="post" action="' . $e($base) . '" style="display:grid;gap:6px">'
                . '<input type="hidden" name="token" value="' . $e($token) . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="back" value="' . $e($back) . '">'
                . '<div style="display:flex;gap:6px"><select name="status" style="padding:4px">' . $sel . '</select></div>'
                . '<textarea name="note" rows="2" placeholder="Заметка администратора" style="padding:4px">' . $e($r['note']) . '</textarea>'
                . '<button style="justify-self:start;padding:4px 12px">Сохранить</button></form></td></tr>';
        }
        return $html . '</tbody></table></div>';
    }
}
