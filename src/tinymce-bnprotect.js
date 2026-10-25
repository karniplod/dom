/* Плагин визуального редактора TinyMCE (админка MODX): теги MODX [[…]] и Fenom {…} в тексте страницы не меняются.
   Без него редактор превращает «=>» в «=&gt;», кавычки в коды и ломает вызовы сниппетов.
   Код прячется в комментарий mce:protected — редактор возвращает его при сохранении как был (то же делает опция protect). */
(function () {
  var FENOM = /^\{(?:\$|\/|if\s|elseif\s|else\}|foreach\s|for\s|while\s|set\s|var\s|include\s|insert\s|block\s|parent\}|switch\s|case\s|default\}|break\}|continue\}|ignore\}|\*|'|")/;

  /* MODX [[…]] со вложенными [[…]] и Fenom {…} со вложенными {…} — целиком */
  function protect(html) {
    var out = '', i = 0, n = html.length;
    while (i < n) {
      var modx = html.substr(i, 2) === '[[';
      var fenom = !modx && html[i] === '{' && FENOM.test(html.substr(i, 12));
      if (!modx && !fenom) { out += html[i++]; continue; }
      var open = modx ? '[[' : '{', close = modx ? ']]' : '}', depth = 0, j = i;
      while (j < n) {
        if (html.substr(j, open.length) === open) { depth++; j += open.length; continue; }
        if (html.substr(j, close.length) === close) { depth--; j += close.length; if (depth === 0) break; continue; }
        j++;
      }
      if (depth !== 0) { out += html[i++]; continue; }   // не закрыт — обычный текст
      out += '<!--mce:protected ' + escape(html.slice(i, j)) + '-->';
      i = j;
    }
    return out;
  }

  tinymce.PluginManager.add('bnprotect', function (editor) {
    editor.on('BeforeSetContent', function (e) {
      if (typeof e.content === 'string' && (e.content.indexOf('[[') >= 0 || e.content.indexOf('{') >= 0)) e.content = protect(e.content);
    });
    return { getMetadata: function () { return { name: 'bnprotect — теги MODX и Fenom без изменений' }; } };
  });
})();
