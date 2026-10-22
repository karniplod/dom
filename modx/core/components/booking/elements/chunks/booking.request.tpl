<div class="bk bk--request" data-bk="request" data-bk-api="[[+api]]" data-bk-id="[[+id]]" data-bk-t="[[+t]]">
  <form class="bk-formgrid bk-find" data-bk-find>
    <label class="bk-field"><span>Номер заявки</span><input name="id" inputmode="numeric" required value="[[+id]]"></label>
    <label class="bk-field"><span>Телефон из заявки</span><input name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+7 (___) ___-__-__" required></label>
    <div class="bk-full"><button class="btn bk-btn" type="submit">Показать заявку</button></div>
  </form>
  <div data-bk-result aria-live="polite"></div>
  [[+form_url:notempty=`<p class="bk-muted">Новая бронь — на странице <a class="bk-link" href="[[+form_url]]">бронирования</a>.</p>`]]
</div>
