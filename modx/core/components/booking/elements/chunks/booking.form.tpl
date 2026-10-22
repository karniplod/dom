<div class="bk bk--full">
<form class="bk-form" id="[[+uid]]" data-bk="guest" data-bk-mode="full" data-bk-api="[[+api]]" data-bk-houses="[[+houses]]" data-bk-today="[[+today]]"
      data-si-form="booking_request" data-si-preset="booking_request" novalidate>
  <input type="hidden" name="client" value="[[+client]]">
  <input type="hidden" name="date_from" value="">
  <input type="hidden" name="date_to" value="">
  <div class="bk-formgrid">
    <label class="bk-field"><span>Дом</span>
      <select name="house" required><option value="">Выберите дом</option>[[+options]]</select>
      <b class="bk-err" data-si-error="house"></b></label>
    <fieldset class="bk-field bk-kind" data-bk-kind hidden><legend>Что бронируете</legend>
      <div class="bk-chips">
        <label><input type="radio" name="addon" value="0" checked><span>Дом на сутки</span></label>
        <label><input type="radio" name="addon" value="1"><span>Только баня</span></label>
      </div><b class="bk-err" data-si-error="addon"></b></fieldset>
  </div>

  <div class="bk-when">
    <p class="bk-dates" data-bk-dates aria-live="polite">Выберите дату заезда</p>
    <div class="bk-cal" data-bk-cal></div>
    <div class="bk-bath" data-bk-bath hidden>
      <label class="bk-field"><span>С</span><select name="time_from"></select></label>
      <label class="bk-field"><span>До</span><select name="time_to"></select></label>
      <p class="bk-muted bk-full" data-bk-slots></p>
      <b class="bk-err bk-full" data-si-error="time"></b>
    </div>
    <b class="bk-err" data-si-error="dates" role="alert"></b>
  </div>

  <div class="bk-formgrid">
    <label class="bk-field"><span>Сколько гостей</span><input name="guests" type="number" min="1" max="20" inputmode="numeric" required>
      <b class="bk-err" data-si-error="guests"></b></label>
    <label class="bk-field"><span>Питомцы</span><input name="pets" type="number" min="0" max="10" value="0" inputmode="numeric">
      <b class="bk-err" data-si-error="pets"></b></label>
  </div>

  <div class="bk-quote" data-bk-quote aria-live="polite"></div>

  <div class="bk-formgrid">
    <label class="bk-field"><span>Имя</span><input name="name" type="text" autocomplete="name" required maxlength="100">
      <b class="bk-err" data-si-error="name"></b></label>
    <label class="bk-field"><span>Телефон</span><input name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="+7 (___) ___-__-__" required>
      <b class="bk-err" data-si-error="phone"></b></label>
    <label class="bk-field bk-full"><span>Комментарий</span><textarea name="comment" rows="3" maxlength="2000" placeholder="Например: день рождения, нужен детский стульчик"></textarea></label>
    <label class="bk-check bk-full"><input type="checkbox" name="agree" value="1" required><span>Согласен на обработку персональных данных по <a class="bk-link" href="#privacy">политике конфиденциальности</a></span>
      <b class="bk-err" data-si-error="agree"></b></label>
  </div>
  <p class="bk-note">Скидка [[+discount]]% — при бронировании через нашу группу ВКонтакте. Залог 5&nbsp;000–20&nbsp;000&nbsp;₽ зависит от количества и возраста гостей — администратор назовёт его при подтверждении.</p>
  <p class="bk-vk"><a class="bk-btn bk-btn--ghost bk-btn--sm" href="[[+vk_url]]" target="_blank" rel="noopener">Получить скидку [[+discount]]% ВКонтакте</a></p>
  <button class="btn bk-btn" type="submit">Отправить заявку</button>
</form>
<div class="bk-done" data-bk-done hidden role="status"></div>
</div>
