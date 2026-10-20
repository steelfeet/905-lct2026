(function () {
    'use strict';
    var meta = document.getElementById('docs-meta');
    if (!meta) return;

    var docs;
    try { docs = JSON.parse(meta.textContent); } catch (e) { return; }
    if (!docs || typeof docs !== 'object') return;

    // Для каждого interaction_id — сколько активных документов
    // Добавим «📄 N» к ячейке матрицы с этим взаимодействием
    document.querySelectorAll('table.matrix td.phase[data-project]').forEach(function (td) {
        var id = td.getAttribute('data-project');
        var count = docs[id] || 0;
        if (count <= 0) return;
        var badge = document.createElement('span');
        badge.className = 'sticker-docs-badge';
        badge.title = 'Активных документов: ' + count;
        badge.textContent = '📄' + count;
        badge.style.cssText = 'position:absolute;top:2px;left:2px;font-size:0.6rem;background:rgba(255,255,255,0.85);color:#1f1f1f;padding:1px 3px;border-radius:2px;font-weight:600;line-height:1;';
        td.querySelector('.sticker-cell').appendChild(badge);
    });
})();