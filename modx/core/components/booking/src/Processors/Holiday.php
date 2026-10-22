<?php
namespace Booking\Processors;

use Booking\Model\Holiday as HolidayRow;
use Booking\Model\HolidayPrice;
use Booking\Model\House as HouseRow;
use Booking\Service\BookingError;
use Booking\Service\Pricing;

/**
 * booking_manage_holiday — праздничные даты: op=list | save | delete.
 * holiday_type: pre_holiday_workday → пятница, holiday_before_dayoff → суббота,
 * holiday_before_workday → воскресенье (правило в коде, ст. 6.2).
 * prices{код дома → цена} — особая цена суток этой даты у дома (FR-003a); пусто — по правилу.
 */
class Holiday extends Base
{
    public const TYPES = [
        'pre_holiday_workday' => 'Предпраздничный рабочий день (как пятница)',
        'holiday_before_dayoff' => 'Праздник перед выходным (как суббота)',
        'holiday_before_workday' => 'Праздник перед рабочим днём (как воскресенье)',
    ];

    protected function handle(): array
    {
        switch ($this->str('op', 20) ?: 'list') {
            case 'save':
                return $this->save();
            case 'delete':
                $h = $this->modx->getObject(HolidayRow::class, (int)$this->getProperty('id'));
                if (!$h) {
                    throw new BookingError('Праздник не найден', 404);
                }
                $old = $this->row($h);
                $this->modx->removeCollection(HolidayPrice::class, ['holiday_id' => $h->get('id')]);
                $h->remove();
                $this->booking->log()->write('holiday', (int)$old['id'], 'delete', $old, null);
                return ['id' => $old['id']];
            default:
                $q = $this->modx->newQuery(HolidayRow::class);
                $year = (int)$this->getProperty('year');
                if ($year) {
                    $q->where(['date:>=' => $year . '-01-01', 'date:<=' => $year . '-12-31']);
                } else {
                    $q->where(['date:>=' => date('Y-m-d', strtotime('-30 days'))]);
                }
                $q->sortby('date', 'ASC');
                $out = [];
                foreach ($this->modx->getIterator(HolidayRow::class, $q) as $h) {
                    $out[] = $this->row($h);
                }
                return ['items' => $out, 'types' => self::TYPES];
        }
    }

    protected function row(HolidayRow $h): array
    {
        $a = $h->toArray();
        $a['rule_day'] = Pricing::DAY_LABELS[Pricing::HOLIDAY_RULE[$a['holiday_type']]] ?? '';
        $a['prices'] = [];
        foreach ($this->modx->getIterator(HolidayPrice::class, ['holiday_id' => $h->get('id')]) as $p) {
            $house = $this->modx->getObject(HouseRow::class, $p->get('house_id'));
            if ($house) {
                $a['prices'][$house->get('code')] = (int)$p->get('price');
            }
        }
        $a['prices'] = (object)$a['prices'];
        return $a;
    }

    protected function save(): array
    {
        $id = (int)$this->getProperty('id');
        $date = $this->booking->date($this->str('date', 10), 'date');
        $type = $this->str('holiday_type', 40);
        if (!isset(self::TYPES[$type])) {
            throw BookingError::field('holiday_type', 'Выберите тип праздника');
        }
        $dup = $this->modx->getObject(HolidayRow::class, ['date' => $date, 'id:!=' => $id]);
        if ($dup) {
            throw BookingError::field('date', 'Эта дата уже есть в праздниках');
        }
        $prices = [];
        foreach ($this->arr('prices') as $code => $price) {
            if ($price === '' || $price === null) {
                continue;
            }
            $house = $this->modx->getObject(HouseRow::class, ['code' => (string)$code]);
            if (!$house) {
                throw BookingError::field('prices', 'Неизвестный дом: ' . $code);
            }
            if (!preg_match('~^\d+$~', (string)$price) || (int)$price <= 0) {
                throw BookingError::field('prices', 'Особая цена — целое число больше нуля');
            }
            $prices[(int)$house->get('id')] = (int)$price;
        }
        $h = $id ? $this->modx->getObject(HolidayRow::class, $id) : $this->modx->newObject(HolidayRow::class);
        if (!$h) {
            throw new BookingError('Праздник не найден', 404);
        }
        $old = $id ? $this->row($h) : null;
        $h->fromArray(['date' => $date, 'holiday_type' => $type, 'comment' => $this->str('comment', 255)]);
        $h->save();
        $this->modx->removeCollection(HolidayPrice::class, ['holiday_id' => $h->get('id')]);
        foreach ($prices as $houseId => $price) {
            $p = $this->modx->newObject(HolidayPrice::class);
            $p->fromArray(['holiday_id' => $h->get('id'), 'house_id' => $houseId, 'price' => $price], '', true);
            $p->save();
        }
        $new = $this->row($h);
        $this->booking->log()->write('holiday', (int)$h->get('id'), $id ? 'update' : 'create', $old, $new);
        return $new;
    }
}
