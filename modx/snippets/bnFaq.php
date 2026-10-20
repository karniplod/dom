<?php
/** bnFaq — частые вопросы из поля bn_faq (строки «Вопрос | Ответ»): &id — страница, по умолчанию текущая */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::faqHtml(Bania::faqItems((int)($id ?? 0)));
