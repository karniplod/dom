<?php
/** bnHouses — список домов через чанк: &tpl (bn.tpl.houseCard), &exclude, &only — id или коды домов через запятую */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
$only = str_replace('||', ',', (string)($only ?? ''));
return Bania::listHouses($tpl ?? 'bn.tpl.houseCard', $exclude ?? '', $only);
