/* Kebabish Catering – tilleggsvurdering rev2: live check, progress, cut warnings, unsaved hint.
 * The page works without this script (the server renders the same check at load). */
(function () {
	'use strict';
	var form = document.getElementById('kcr');
	if (!form) { return; }
	var INTENTS = [];
	try { INTENTS = JSON.parse(document.getElementById('kcr-intents').textContent) || []; } catch (e) { return; }
	var dishes = Array.prototype.slice.call(form.querySelectorAll('.dish'));
	var dirty = false;

	function info(d) {
		var c = d.querySelector('input[type=radio]:checked');
		return { el: d, name: d.getAttribute('data-name'), tags: (d.getAttribute('data-tags') || '').split(',').filter(Boolean), rule: d.getAttribute('data-rule'), dec: c ? c.value : '' };
	}
	function covers(it, all) {
		return all.filter(function (d) {
			return d.rule !== 'none' && d.dec !== 'cut' && d.tags.some(function (t) { return it.tags.indexOf(t) !== -1; });
		}).length > 0;
	}
	function render() {
		var all = dishes.map(info);
		var core = INTENTS.filter(function (it) { return !it.nutfree; });
		var broken = core.filter(function (it) { return !covers(it, all); }).map(function (it) { return it.label; });
		var box = document.getElementById('check');
		box.className = 'check' + (broken.length ? ' bad' : '');
		box.textContent = '';
		var b = document.createElement('b');
		b.textContent = broken.length ? 'Disse forslagene forsvinner med valgene dine: ' : 'Alle forslagene virker';
		box.appendChild(b);
		box.appendChild(document.createTextNode(broken.length ? broken.join(', ') + '. Behold minst én rett i hver.' : ' med valgene dine.'));
		var done = all.filter(function (d) { return d.dec; }).length;
		document.getElementById('progress').textContent = done + ' av ' + all.length + ' retter merket';
		all.forEach(function (d) {
			var w = d.el.querySelector('[data-cutwarn]');
			var hit = d.dec === 'cut' ? core.filter(function (it) { return it.tags.some(function (t) { return d.tags.indexOf(t) !== -1; }) && !covers(it, all); }) : [];
			w.hidden = !hit.length;
			w.textContent = hit.length ? 'Uten denne forsvinner: ' + hit.map(function (x) { return x.label; }).join(', ') + '.' : '';
		});
	}
	function mark() {
		dirty = true;
		document.getElementById('dirty').textContent = 'Ikke lagret ennå.';
	}
	form.addEventListener('change', function () { mark(); render(); });
	form.addEventListener('input', mark);
	form.addEventListener('submit', function () { dirty = false; });
	window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
	render();
}());
