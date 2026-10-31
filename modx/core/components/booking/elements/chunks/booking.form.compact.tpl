<div class="bk bk--compact">
<form class="bk-form" id="[[+uid]]" data-bk="guest" data-bk-mode="compact" data-bk-api="[[+api]]" data-bk-houses="[[+houses]]" data-bk-today="[[+today]]"
      data-si-form="booking_request" data-si-preset="booking_request" novalidate>
  <input type="hidden" name="client" value="[[+client]]">
  <input type="hidden" name="date_from" value="">
  <input type="hidden" name="date_to" value="">
  <input type="hidden" name="pets" value="0">
  <div class="bk-formgrid">
    <label class="bk-field bk-full"><span>Дом</span>
      <select name="house" required><option value="">Выберите Дом</option>[[+options]]</select>
      <b class="bk-err" data-si-error="house"></b></label>
    <fieldset class="bk-field bk-full bk-kind" data-bk-kind hidden><legend>Что бронируете</legend>
      <div class="bk-chips">
        <label><input type="radio" name="addon" value="0" checked><span>Дом на сутки</span></label>
        <label><input type="radio" name="addon" value="1"><span>Только баня</span></label>
      </div><b class="bk-err" data-si-error="addon"></b></fieldset>
    <div class="bk-field bk-full"><span>Даты</span>
      <button type="button" class="bk-datebtn" data-bk-dates aria-live="polite">Выбрать даты</button>
      <div class="bk-cal bk-cal--pop" data-bk-cal></div>
      <b class="bk-err" data-si-error="dates" role="alert"></b></div>
    <div class="bk-bath bk-full" data-bk-bath hidden>
      <label class="bk-field"><span>С</span><select name="time_from"></select></label>
      <label class="bk-field"><span>До</span><select name="time_to"></select></label>
      <p class="bk-muted bk-full" data-bk-slots></p>
      <b class="bk-err bk-full" data-si-error="time"></b>
    </div>
    <label class="bk-field"><span>Сколько гостей</span><input name="guests" type="number" min="1" max="20" inputmode="numeric" required>
      <b class="bk-err" data-si-error="guests"></b></label>
    <label class="bk-field"><span>Телефон</span><input name="phone" type="tel" autocomplete="tel" inputmode="tel" placeholder="+7 (___) ___-__-__" required>
      <b class="bk-err" data-si-error="phone"></b></label>
    <label class="bk-field bk-full"><span>Имя</span><input name="name" type="text" autocomplete="name" required maxlength="100">
      <b class="bk-err" data-si-error="name"></b></label>
  </div>
  <div class="bk-quote" data-bk-quote aria-live="polite"></div>
  <label class="bk-check"><input type="checkbox" name="agree" value="1" required><span>Даю <a class="bk-link" href="[[+soglasie_url]]" target="_blank">согласие на обработку персональных данных</a> и принимаю <a class="bk-link" href="[[+politika_url]]" target="_blank">Политику обработки персональных данных</a></span>
    <b class="bk-err" data-si-error="agree"></b></label>
  <p class="bk-note">Залог 5&nbsp;000–20&nbsp;000&nbsp;₽ зависит от количества и возраста гостей — администратор назовёт его при подтверждении.</p>
  <p class="bk-vk"><a class="bk-btn bk-btn--ghost bk-btn--sm" href="[[+vk_url]]" target="_blank" rel="noopener">Мы во ВКонтакте</a></p>
  <button class="btn bk-btn" type="submit">Отправить заявку</button>
</form>
<div class="bk-done" data-bk-done hidden role="status"></div>
</div>
