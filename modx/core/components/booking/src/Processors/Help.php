<?php
namespace Booking\Processors;

/**
 * booking_help — инструкция для администраторов (чанк booking.help.admin.tpl): только вошедшим —
 * в админке MODX (вкладка «Инструкция») и в приложении по ключу. Гостям текст не отдаётся и в коде страниц его нет.
 */
class Help extends Base
{
    protected function handle(): array
    {
        $modx = $this->modx;
        $app = '';
        if (($fp = (int)$modx->getOption('booking.form_page'))
            && ($r = $modx->getObject(\MODX\Revolution\modResource::class, ['alias' => 'app', 'parent' => $fp, 'published' => 1, 'deleted' => 0]))) {
            $app = str_replace('&amp;', '&', (string)$modx->makeUrl($r->get('id'), '', '', 'full'));
        }
        return ['html' => $modx->getChunk('booking.help.admin.tpl', ['app_url' => htmlspecialchars($app), 'site_url' => htmlspecialchars((string)$modx->getOption('site_url'))])];
    }
}
