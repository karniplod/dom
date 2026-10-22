<div style="font:15px/1.5 Arial,sans-serif;color:#22140E;max-width:560px">
<h2 style="font-weight:normal;margin:0 0 12px">Заявка №[[+booking_id]] — [[+house_name]]</h2>
<p style="margin:0 0 12px"><b>[[+dates]]</b><br>Гостей: [[+guests]][[+pets:gt=`0`:then=`, питомцев: [[+pets]]`]]</p>
<table cellpadding="4" style="border-collapse:collapse;margin:0 0 12px">[[+lines]]
<tr><td><b>Итого</b></td><td style="text-align:right"><b>[[+total]]</b></td></tr></table>
<p style="margin:0 0 12px">[[+guest_name]], <a href="tel:[[+guest_phone]]">[[+guest_phone]]</a>[[+guest_comment:notempty=`<br>Комментарий: [[+guest_comment]]`]]</p>
<p style="margin:0 0 12px">Заявка новая — даты станут заняты после подтверждения.</p>
<p style="margin:0"><a href="[[+admin_link]]">Открыть в админке</a></p>
</div>
