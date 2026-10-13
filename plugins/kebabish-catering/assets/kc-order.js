/* Kebabish Catering 1.1.8-occ1 – live selection summary. Display only; the server recalculates every price. */
(function () {
	'use strict';

	var form = document.querySelector('form.kco');
	if (!form) {
		return;
	}

	var decimals = parseInt(form.getAttribute('data-decimals') || '2', 10);
	var decSep = form.getAttribute('data-dec-sep') || ',';
	var thouSep = form.getAttribute('data-thou-sep') || ' ';
	var minPersons = parseInt(form.getAttribute('data-min-persons') || '0', 10);

	var items = Array.prototype.slice.call(form.querySelectorAll('[data-kco-item]'));
	var linesEl = form.querySelector('[data-kco-lines]');
	var emptyEl = form.querySelector('[data-kco-empty]');
	var totalRow = form.querySelector('[data-kco-total-row]');
	var totalEl = form.querySelector('[data-kco-total]');
	var warnEl = form.querySelector('[data-kco-min-warn]');
	var personsRow = form.querySelector('[data-kco-persons-row]');
	var personsEl = form.querySelector('[data-kco-persons]');

	function money(n) {
		var fixed = Math.abs(n).toFixed(decimals);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thouSep);
		return (n < 0 ? '−' : '') + 'kr ' + parts.join(decSep);
	}

	function inputOf(item) {
		return item.querySelector('.kco-stepper__input');
	}

	function qtyOf(item) {
		var v = parseInt(inputOf(item).value, 10);
		return isNaN(v) || v < 0 ? 0 : v;
	}

	function setQty(item, value) {
		inputOf(item).value = String(Math.max(0, Math.min(9999, value)));
		update();
	}

	function step(item, dir) {
		var min = parseInt(item.getAttribute('data-min') || '1', 10);
		var q = qtyOf(item);
		if (dir > 0) {
			setQty(item, q === 0 ? min : q + 1);
		} else {
			setQty(item, q <= min ? 0 : q - 1);
		}
	}

	function update() {
		var total = 0;
		var persons = 0;
		var hasPackage = false;
		var belowMin = [];
		linesEl.innerHTML = '';

		items.forEach(function (item) {
			var q = qtyOf(item);
			item.classList.toggle('is-selected', q > 0);
			if (q === 0) {
				return;
			}
			var price = parseFloat(item.getAttribute('data-price') || '0');
			var min = parseInt(item.getAttribute('data-min') || '1', 10);
			var unit = q === 1 ? item.getAttribute('data-unit-one') : item.getAttribute('data-unit-many');
			var name = item.getAttribute('data-name');
			var sum = price * q;
			total += sum;
			if (item.getAttribute('data-role') === 'package') {
				hasPackage = true;
				persons += q;
			}
			if (q < min) {
				belowMin.push(name + ' krever minst ' + min);
			}

			var li = document.createElement('li');
			var label = document.createElement('span');
			label.textContent = name + ' · ' + q + ' ' + unit;
			var amount = document.createElement('span');
			amount.textContent = money(sum);
			li.appendChild(label);
			li.appendChild(amount);
			linesEl.appendChild(li);
		});

		var any = linesEl.children.length > 0;
		emptyEl.hidden = any;
		totalRow.hidden = !any;
		totalEl.textContent = money(total);
		personsRow.hidden = persons === 0;
		personsEl.textContent = persons + (persons === 1 ? ' person' : ' personer');

		var warn = '';
		if (belowMin.length) {
			warn = belowMin.join('. ') + '.';
		} else if (any && !hasPackage) {
			warn = 'Velg minst én pakke.';
		} else if (any && persons < minPersons) {
			warn = 'Minste bestilling er ' + minPersons + ' personer totalt (nå ' + persons + '). Fordel fritt mellom pakkene.';
		}
		warnEl.textContent = warn;
		warnEl.hidden = warn === '';
	}

	items.forEach(function (item) {
		item.querySelectorAll('[data-kco-step]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				step(item, parseInt(btn.getAttribute('data-kco-step'), 10));
			});
		});
		inputOf(item).addEventListener('input', update);
		inputOf(item).addEventListener('change', function () {
			setQty(item, qtyOf(item));
		});
	});

	// Occasion cards can be reused on other landing pages; the form remains editable.
	var occasionSelect = form.querySelector('[name="kc_occasion"]');
	if (occasionSelect) {
		Array.prototype.slice.call(document.querySelectorAll('[data-kc-occasion]')).forEach(function (card) {
			card.addEventListener('click', function (event) {
				var value = card.getAttribute('data-kc-occasion');
				if (Array.prototype.some.call(occasionSelect.options, function (o) { return o.value === value; })) {
					// Same page: no reload. Select, jump to the form, put focus on the editable field.
					event.preventDefault();
					occasionSelect.value = value;
					occasionSelect.dispatchEvent(new Event('change', { bubbles: true }));
					var target = document.getElementById('foresporsel');
					if (target && target.scrollIntoView) {
						target.scrollIntoView({ behavior: 'smooth', block: 'start' });
					}
					occasionSelect.focus({ preventScroll: true });
				}
			});
		});
		// URL prefills work on the current catering page and the later dedicated landing.
		var requested = new URLSearchParams(window.location.search).get('occasion');
		if (requested && !occasionSelect.value && Array.prototype.some.call(occasionSelect.options, function (o) { return o.value === requested; })) {
			occasionSelect.value = requested;
		}
	}

	// Prevent double submit.
	form.addEventListener('submit', function () {
		var btn = form.querySelector('button[type="submit"]');
		if (btn) {
			btn.disabled = true;
			btn.setAttribute('aria-busy', 'true');
		}
	});

	// Re-enable the button if the page is restored from the back/forward cache.
	window.addEventListener('pageshow', function () {
		var btn = form.querySelector('button[type="submit"]');
		if (btn) {
			btn.disabled = false;
			btn.removeAttribute('aria-busy');
		}
	});

	// After a failed submit, move focus to the error summary.
	var errors = document.getElementById('kco-errors');
	if (errors) {
		errors.focus();
	}

	update();
})();
