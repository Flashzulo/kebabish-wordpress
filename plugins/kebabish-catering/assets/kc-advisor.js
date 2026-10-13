/* Kebabish Catering 1.1.8-v2 – catering advisor (architecture V2, Saulo D1–D13).
 *
 * S1 spørsmål -> S2 pakker -> S3 tillegg -> S4 se over + bekreft -> S5 kontakt/henting -> send.
 * - Packages are neutral (D1). Answers only steer add-on suggestions (D2), max 3 with a reason (D7).
 * - Nothing is added automatically (D4): quantities change only on a customer click/entry.
 * - ONE write port for quantities: setQty() -> hidden kc_qty[id] inputs. The server reprices everything.
 * - Needs (mild, vegetar, allergi, matpause) fill the editable comment field (D8, D9).
 * - Only textContent is used for data; no network calls.
 */
(function () {
	'use strict';

	var form = document.getElementById('kca-form');
	var dlg = document.getElementById('kca');
	if (!form || !dlg) {
		return;
	}

	var CFG, BOOT;
	try {
		CFG = JSON.parse(dlg.getAttribute('data-kca-config') || '{}');
		BOOT = JSON.parse(dlg.getAttribute('data-kca-boot') || '{}') || {};
	} catch (e) {
		return;
	}
	if (!CFG.occasions || !CFG.catalog) {
		return;
	}

	var T = CFG.texts || {};
	var OCC = CFG.occasions;
	var PKGS = CFG.catalog.packages || [];
	var ADDS = CFG.catalog.addons || [];
	var BY_ID = {};
	PKGS.concat(ADDS).forEach(function (p) { BY_ID[p.id] = p; });
	var MIN_PERSONS = parseInt(CFG.minPersons || 0, 10) || 0;
	var SCREENS = ['q', 'pkg', 'add', 'review', 'contact'];
	var STEP_LABELS = ['Spørsmål', 'Pakker', 'Tillegg', 'Se over', 'Kontakt'];

	var decimals = parseInt(form.getAttribute('data-decimals') || '2', 10);
	var decSep = form.getAttribute('data-dec-sep') || ',';
	var thouSep = form.getAttribute('data-thou-sep') || ' ';

	var el = {
		body: dlg.querySelector('[data-kca-body]'),
		contact: dlg.querySelector('[data-kca-contact]'),
		title: dlg.querySelector('[data-kca-title]'),
		kicker: dlg.querySelector('[data-kca-kicker]'),
		steps: dlg.querySelector('[data-kca-steps]'),
		back: dlg.querySelector('[data-kca-back]'),
		next: dlg.querySelector('[data-kca-next]'),
		total: dlg.querySelector('[data-kca-total]'),
		close: dlg.querySelector('[data-kca-close]'),
		fields: form.querySelector('[data-kca-fields]'),
		occasion: form.querySelector('[data-kca-occasion]'),
		note: form.querySelector('[name="kc_note"]'),
		time: form.querySelector('[name="kc_time"]'),
		allergy: dlg.querySelector('[data-kca-allergy]'),
		pickupHint: dlg.querySelector('[data-kca-pickup-hint]'),
		start: document.querySelector('[data-kca-start]'),
		resume: document.querySelector('[data-kca-resume]')
	};
	var live = document.createElement('p');
	live.className = 'kca-sr';
	live.setAttribute('aria-live', 'polite');
	dlg.querySelector('.kca__sheet').appendChild(live);

	var S = {
		occ: '',
		answers: {},
		needs: {},
		qty: {},
		shown: [],
		screen: 'q',
		qi: 0,
		lastAuto: null,
		pickupAuto: '',
		error: ''
	};
	var pendingFocus = null;
	var submitting = false;

	/* ---------------------------------------------------------------- */
	/* Helpers                                                           */
	/* ---------------------------------------------------------------- */

	function h(tag, attrs, kids) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) {
					return;
				}
				if (k === 'text') {
					n.textContent = v;
				} else if (k === 'on') {
					Object.keys(v).forEach(function (ev) { n.addEventListener(ev, v[ev]); });
				} else if (k === 'className') {
					n.className = v;
				} else {
					n.setAttribute(k, v === true ? '' : v);
				}
			});
		}
		(kids || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) {
				return;
			}
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}

	function money(n) {
		var fixed = Math.abs(n).toFixed(decimals);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thouSep);
		return 'kr ' + parts.join(decSep);
	}

	function fmt(s, v) {
		return String(s).replace(/%[sd]/, String(v));
	}

	function int(v) {
		var n = parseInt(v, 10);
		return isNaN(n) ? 0 : n;
	}

	function cfg() {
		return OCC[S.occ] || null;
	}

	function steps() {
		return cfg() ? cfg().steps : [];
	}

	function guests() {
		var a = S.answers;
		if (a.count) {
			return int(a.count);
		}
		return int(a.adults) + int(a.children);
	}

	function children() {
		return int(S.answers.children);
	}

	function selected() {
		var out = [];
		steps().forEach(function (st) {
			var v = S.answers[st.id];
			if (Array.isArray(v)) {
				out = out.concat(v);
			} else if (typeof v === 'string') {
				out.push(v);
			}
		});
		return out;
	}

	function has(v) {
		return selected().indexOf(v) !== -1;
	}

	function unitLabel(p, n) {
		return n === 1 ? p.unit1 : p.unitN;
	}

	function say(msg) {
		live.textContent = '';
		window.setTimeout(function () { live.textContent = msg; }, 30);
	}

	/* ---------------------------------------------------------------- */
	/* Quantities: the ONE write port                                    */
	/* ---------------------------------------------------------------- */

	function hiddenQty(id) {
		return form.querySelector('[data-kca-qty] input[data-id="' + id + '"]');
	}

	function setQty(id, n) {
		var p = BY_ID[id];
		if (!p) {
			return;
		}
		var cur = S.qty[id] || 0;
		n = Math.max(0, Math.min(999, int(n)));
		if (n > 0 && n < p.min) {
			n = n > cur ? p.min : 0; // step up from 0 jumps to the minimum; below the minimum removes
		}
		if (n === 0) {
			delete S.qty[id];
		} else {
			S.qty[id] = n;
		}
		var inp = hiddenQty(id);
		if (inp) {
			inp.value = String(n);
		}
	}

	function persons() {
		return PKGS.reduce(function (s, p) { return s + (S.qty[p.id] || 0); }, 0);
	}

	function total() {
		return Object.keys(S.qty).reduce(function (s, id) {
			var p = BY_ID[id];
			return s + (p ? p.price * S.qty[id] : 0);
		}, 0);
	}

	/* ---------------------------------------------------------------- */
	/* Suggestion engine (D2, D3, D7)                                    */
	/* ---------------------------------------------------------------- */

	function intents() {
		var list = [];
		var c = children();
		if (has('vegetarian')) {
			var vn = int(S.needs.veg_count);
			list.push({ tags: ['vegetarian'], reason: vn ? vn + ' spiser vegetar' : 'Noen spiser vegetar', base: vn, none: T.veg_none });
		}
		if (has('mild')) {
			list.push({ tags: ['mild'], reason: 'Dere ønsket mild mat', base: 0, none: T.mild_none });
		}
		if (c > 0) {
			list.push({ tags: ['kids', 'mild'], reason: 'Til barna (' + c + ')', base: c });
		}
		var oi = (cfg() && cfg().intents) || {};
		steps().forEach(function (st) {
			var v = S.answers[st.id];
			(Array.isArray(v) ? v : (typeof v === 'string' ? [v] : [])).forEach(function (val) {
				(oi[val] || []).forEach(function (it) {
					list.push({ tags: it.tags, reason: it.reason, base: 0 });
				});
			});
		});
		[['drink'], ['bread'], ['sauce']].forEach(function (tags) {
			list.push({ tags: tags, reason: T.general || 'Tilbehør til maten', base: 0 });
		});
		var seen = {};
		return list.filter(function (it) {
			var k = it.tags.join('+');
			if (seen[k]) {
				return false;
			}
			seen[k] = 1;
			return true;
		});
	}

	function tagged(p, tags) {
		return tags.some(function (t) { return p.tags.indexOf(t) !== -1; });
	}

	function suggestQty(p, it) {
		var n = guests() || MIN_PERSONS || 1;
		var q;
		switch (p.rule) {
			case 'guest': q = n; break;
			case 'guest4': q = Math.ceil(n / 4); break;
			case 'group': q = it.base || Math.ceil(n / 4); break;
			default: q = 1;
		}
		return Math.max(p.min, Math.min(999, q));
	}

	function suggestions() {
		var out = [];
		var notes = [];
		var taken = {};
		intents().forEach(function (it) {
			if (out.length >= 3) {
				return;
			}
			// Intent already covered by something the customer chose -> the next one moves up.
			if (ADDS.some(function (p) { return S.qty[p.id] && tagged(p, it.tags); })) {
				return;
			}
			var pick = null;
			it.tags.some(function (t) {
				pick = ADDS.filter(function (p) {
					return p.rule !== 'none' && p.tags.indexOf(t) !== -1 && !taken[p.id] && !S.qty[p.id];
				})[0] || null;
				return !!pick;
			});
			if (!pick) {
				if (it.none && !ADDS.some(function (p) { return tagged(p, it.tags); })) {
					notes.push(it.none);
				}
				return;
			}
			taken[pick.id] = 1;
			out.push({ p: pick, reason: it.reason, qty: suggestQty(pick, it) });
		});
		out.forEach(function (s) {
			if (S.shown.indexOf(s.p.id) === -1 && S.shown.length < 20) {
				S.shown.push(s.p.id);
			}
		});
		return { list: out, notes: notes };
	}

	/* ---------------------------------------------------------------- */
	/* Comment auto-fill (D8)                                            */
	/* ---------------------------------------------------------------- */

	function autoNote() {
		var N = T.note || {};
		var lines = [];
		if (has('mild')) {
			lines.push(N.mild);
		}
		if (has('vegetarian')) {
			lines.push(S.needs.veg_count ? fmt(N.veg, int(S.needs.veg_count)) : N.veg_unknown);
		}
		if (has('allergies') && S.needs.allergy_text) {
			lines.push(fmt(N.allergy, S.needs.allergy_text) + (S.needs.allergy_count ? fmt(N.allergy_count, int(S.needs.allergy_count)) : ''));
			if (S.needs.cross_contact) {
				lines.push(N.cross);
			}
		}
		if (S.answers.break_time && S.answers.break_time !== 'unknown') {
			lines.push(fmt(N['break'], S.answers.break_time));
		}
		if (children() > 0) {
			lines.push(fmt(N.children, children()));
		}
		return lines.filter(Boolean).join('\n');
	}

	function applyNote() {
		if (!el.note) {
			return;
		}
		var auto = autoNote();
		var cur = el.note.value;
		var prev = S.lastAuto;
		var next = cur;
		function join(rest) {
			return [auto, rest.replace(/^\s+/, '')].filter(Boolean).join('\n\n');
		}
		if (prev === null || prev === '') {
			if (auto && cur.indexOf(auto) === 0) {
				next = cur;
			} else {
				next = join(cur);
			}
		} else if (cur.indexOf(prev) === 0) {
			next = join(cur.slice(prev.length));
		} else if (auto !== prev && window.confirm('Du har endret kommentaren. Vil du oppdatere den med de nye svarene dine? Teksten du har skrevet om allergi og behov erstattes.')) {
			next = auto;
		}
		el.note.value = next.slice(0, 1000);
		S.lastAuto = auto;
	}

	/* ---------------------------------------------------------------- */
	/* Hidden answer fields (server whitelists them again)               */
	/* ---------------------------------------------------------------- */

	function writeHidden() {
		if (el.occasion) {
			el.occasion.value = S.occ;
		}
		if (!el.fields) {
			return;
		}
		while (el.fields.firstChild) {
			el.fields.removeChild(el.fields.firstChild);
		}
		if (!S.occ) {
			return;
		}
		var all = { occasion: S.occ };
		Object.keys(S.answers).forEach(function (k) { all[k] = S.answers[k]; });
		Object.keys(S.needs).forEach(function (k) { all[k] = S.needs[k]; });
		if (S.shown.length) {
			all.shown = S.shown;
		}
		Object.keys(all).forEach(function (k) {
			var v = all[k];
			el.fields.appendChild(h('input', { type: 'hidden', name: 'kc_adv[' + k + ']', value: Array.isArray(v) ? v.join(',') : String(v) }));
		});
	}

	function restore() {
		form.querySelectorAll('[data-kca-qty] input[data-id]').forEach(function (inp) {
			var n = int(inp.value);
			if (n > 0 && BY_ID[inp.getAttribute('data-id')]) {
				S.qty[inp.getAttribute('data-id')] = n;
			}
		});
		var raw = {};
		if (el.fields) {
			el.fields.querySelectorAll('input[name^="kc_adv["]').forEach(function (inp) {
				var m = inp.name.match(/^kc_adv\[([a-z_]+)\]$/);
				if (m) {
					raw[m[1]] = inp.value;
				}
			});
		}
		var occ = raw.occasion || (el.occasion ? el.occasion.value : '');
		if (!OCC[occ]) {
			return;
		}
		S.occ = occ;
		OCC[occ].steps.forEach(function (st) {
			if (st.type === 'pair') {
				Object.keys(st.fields).forEach(function (f) {
					if (raw[f] !== undefined && raw[f] !== '') {
						S.answers[f] = int(raw[f]);
					}
				});
			} else if (raw[st.id] !== undefined && raw[st.id] !== '') {
				S.answers[st.id] = st.type === 'multi' ? raw[st.id].split(',') : (st.type === 'number' ? int(raw[st.id]) : raw[st.id]);
			}
		});
		Object.keys(CFG.needFields || {}).forEach(function (trig) {
			Object.keys(CFG.needFields[trig]).forEach(function (k) {
				if (raw[k] !== undefined && raw[k] !== '') {
					S.needs[k] = CFG.needFields[trig][k].type === 'text' ? raw[k] : int(raw[k]);
				}
			});
		});
		if (raw.shown) {
			S.shown = raw.shown.split(',').map(int).filter(Boolean).slice(0, 20);
		}
		var auto = autoNote();
		S.lastAuto = auto && el.note && el.note.value.indexOf(auto) === 0 ? auto : null;
	}

	/* ---------------------------------------------------------------- */
	/* Pickup time from the meeting break (D6 S5)                       */
	/* ---------------------------------------------------------------- */

	function pickupSuggestion() {
		var bt = S.answers.break_time;
		if (!bt || bt === 'unknown' || !CFG.slots || !CFG.slots.length) {
			return null;
		}
		var p = bt.split(':');
		var limit = int(p[0]) * 60 + int(p[1]) - (CFG.breakLead || 30);
		var best = null;
		CFG.slots.forEach(function (s) {
			var q = s.split(':');
			if (int(q[0]) * 60 + int(q[1]) <= limit) {
				best = s;
			}
		});
		return { slot: best, at: bt };
	}

	function applyPickup() {
		if (!el.pickupHint || !el.time) {
			return;
		}
		var sug = pickupSuggestion();
		el.pickupHint.hidden = true;
		if (!sug) {
			return;
		}
		if (!sug.slot) {
			el.pickupHint.textContent = 'Matpausen kl. ' + sug.at + ' er tidligere enn første hentetid (' + CFG.slots[0] + '). Velg tid, så avklarer Kebabish.';
			el.pickupHint.hidden = false;
			return;
		}
		if (el.time.value === '' || el.time.value === S.pickupAuto) {
			el.time.value = sug.slot;
			S.pickupAuto = sug.slot;
		}
		el.pickupHint.textContent = 'Foreslått hentetid kl. ' + sug.slot + ' – litt før matpausen kl. ' + sug.at + '. Du kan endre den.';
		el.pickupHint.hidden = false;
	}

	function allergyLines() {
		var out = [];
		if (has('allergies') && S.needs.allergy_text) {
			out.push('Ditt allergivalg: ' + S.needs.allergy_text + (S.needs.allergy_count ? ' – gjelder ' + int(S.needs.allergy_count) + ' gjester.' : ''));
			if (S.needs.cross_contact) {
				out.push('Du er bekymret for krysskontakt.');
			}
			out.push(T.allergy);
		}
		return out;
	}

	/* ---------------------------------------------------------------- */
	/* Components                                                        */
	/* ---------------------------------------------------------------- */

	function stepper(p, ctx) {
		var n = S.qty[p.id] || 0;
		var inputId = 'kca-q-' + ctx + '-' + p.id;
		var inp = h('input', {
			id: inputId, type: 'number', inputmode: 'numeric', min: '0', max: '999', step: '1', value: String(n),
			form: 'kca-none', 'data-kca-key': 'in-' + ctx + '-' + p.id,
			on: {
				change: function () { setQty(p.id, inp.value); pendingFocus = 'in-' + ctx + '-' + p.id; render(); },
				keydown: function (e) { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } }
			}
		});
		return h('div', { className: 'kca-step' }, [
			h('button', { type: 'button', className: 'kca-step__btn', 'aria-label': 'Færre – ' + p.name, 'data-kca-key': 'dec-' + ctx + '-' + p.id, disabled: n === 0, on: { click: function () { setQty(p.id, n - 1); pendingFocus = 'dec-' + ctx + '-' + p.id; render(); } } }, ['−']),
			h('label', { className: 'kca-sr', 'for': inputId, text: 'Antall ' + p.unitN + ' – ' + p.name }),
			inp,
			h('button', { type: 'button', className: 'kca-step__btn', 'aria-label': 'Flere – ' + p.name, 'data-kca-key': 'inc-' + ctx + '-' + p.id, on: { click: function () { setQty(p.id, n + 1); pendingFocus = 'inc-' + ctx + '-' + p.id; render(); } } }, ['+'])
		]);
	}

	function priceLine(p) {
		return money(p.price) + ' ' + p.per;
	}

	function errorBox(msg) {
		return h('p', { className: 'kca-err', role: 'alert', text: msg });
	}

	/* ---------------------------------------------------------------- */
	/* S1 questions                                                      */
	/* ---------------------------------------------------------------- */

	function numberInput(id, label, val, min, key) {
		var inp = h('input', {
			id: 'kca-f-' + id, type: 'number', inputmode: 'numeric', min: String(min), max: '999', step: '1',
			value: val === undefined || val === '' ? '' : String(val), form: 'kca-none', 'data-kca-key': key,
			on: { input: function () { if (inp.value === '') { delete S.answers[id]; } else { S.answers[id] = Math.min(999, Math.max(min, int(inp.value))); } } }
		});
		return h('label', { className: 'kca-num', 'for': 'kca-f-' + id }, [h('span', { text: label }), inp]);
	}

	function needReveal(trigger) {
		var fields = (CFG.needFields || {})[trigger];
		if (!fields) {
			return null;
		}
		var box = h('div', { className: 'kca-reveal', 'data-kca-reveal': trigger });
		Object.keys(fields).forEach(function (k) {
			var f = fields[k];
			var id = 'kca-n-' + k;
			if (f.type === 'count') {
				var c = h('input', {
					id: id, type: 'number', inputmode: 'numeric', min: '1', max: '999', step: '1', form: 'kca-none',
					value: S.needs[k] ? String(S.needs[k]) : '', 'data-kca-key': 'need-' + k, required: f.required || null,
					on: { input: function () { if (int(c.value) > 0) { S.needs[k] = Math.min(999, int(c.value)); } else { delete S.needs[k]; } } }
				});
				box.appendChild(h('label', { className: 'kca-num', 'for': id }, [h('span', { text: f.label + (f.required ? '' : ' (valgfritt)') }), c]));
			} else if (f.type === 'text') {
				var t = h('textarea', {
					id: id, rows: '3', maxlength: String(CFG.textMax || 300), form: 'kca-none', 'data-kca-key': 'need-' + k,
					required: f.required || null, 'aria-describedby': id + '-hint',
					on: { input: function () { var v = t.value.slice(0, CFG.textMax || 300); if (v.trim()) { S.needs[k] = v; } else { delete S.needs[k]; } } }
				});
				t.value = S.needs[k] || '';
				box.appendChild(h('label', { className: 'kca-num', 'for': id }, [h('span', { text: f.label }), t]));
				if (f.hint) {
					box.appendChild(h('p', { className: 'kca-hint', id: id + '-hint', text: f.hint }));
				}
			} else if (f.type === 'flag') {
				var cb = h('input', {
					id: id, type: 'checkbox', form: 'kca-none', checked: !!S.needs[k], 'data-kca-key': 'need-' + k,
					on: { change: function () { if (cb.checked) { S.needs[k] = 1; } else { delete S.needs[k]; } } }
				});
				box.appendChild(h('label', { className: 'kca-choice kca-choice--flag', 'for': id }, [cb, h('span', { text: f.label })]));
			}
		});
		if (trigger === 'allergies') {
			box.appendChild(h('p', { className: 'kca-note-box', text: T.allergy }));
		}
		return box;
	}

	function clearNeeds(trigger) {
		Object.keys((CFG.needFields || {})[trigger] || {}).forEach(function (k) { delete S.needs[k]; });
	}

	function renderQuestion() {
		var st = steps()[S.qi];
		var wrap = h('div', { className: 'kca-q' });
		if (S.qi === 0 && cfg().intro) {
			wrap.appendChild(h('p', { className: 'kca-intro', text: cfg().intro }));
		}
		if (st.hint) {
			wrap.appendChild(h('p', { className: 'kca-hint', text: st.hint }));
		}
		if (st.type === 'number') {
			wrap.appendChild(numberInput(st.id, 'Antall', S.answers[st.id], st.min || 1, 'q-' + st.id));
		} else if (st.type === 'pair') {
			var pair = h('div', { className: 'kca-pair' });
			Object.keys(st.fields).forEach(function (f) {
				pair.appendChild(numberInput(f, st.fields[f].label, S.answers[f], st.fields[f].min || 0, 'q-' + f));
			});
			wrap.appendChild(pair);
		} else if (st.type === 'time') {
			var sel = h('select', {
				id: 'kca-f-' + st.id, form: 'kca-none', 'data-kca-key': 'q-' + st.id,
				on: { change: function () { if (sel.value) { S.answers[st.id] = sel.value; } else { delete S.answers[st.id]; } } }
			}, [h('option', { value: '', text: 'Velg tidspunkt' })]);
			Object.keys(st.options).forEach(function (v) {
				sel.appendChild(h('option', { value: v, text: st.options[v], selected: S.answers[st.id] === v }));
			});
			wrap.appendChild(h('label', { className: 'kca-num', 'for': 'kca-f-' + st.id }, [h('span', { text: 'Tidspunkt' }), sel]));
		} else {
			var multi = st.type === 'multi';
			var list = h('div', { className: 'kca-choices', role: multi ? 'group' : 'radiogroup', 'aria-labelledby': 'kca-title' });
			var cur = S.answers[st.id];
			Object.keys(st.options).forEach(function (v) {
				var id = 'kca-o-' + st.id + '-' + v;
				var checked = multi ? (Array.isArray(cur) && cur.indexOf(v) !== -1) : cur === v;
				var inp = h('input', {
					id: id, type: multi ? 'checkbox' : 'radio', name: 'kca-' + st.id, value: v, form: 'kca-none',
					checked: checked, 'data-kca-key': 'o-' + st.id + '-' + v,
					on: { change: function () { onChoice(st, v, inp.checked); } }
				});
				list.appendChild(h('label', { className: 'kca-choice', 'for': id }, [inp, h('span', { text: st.options[v] })]));
				if (multi && checked) {
					var rev = needReveal(v);
					if (rev) {
						list.appendChild(rev);
					}
				}
			});
			wrap.appendChild(list);
		}
		if (S.error) {
			wrap.appendChild(errorBox(S.error));
		}
		return wrap;
	}

	function onChoice(st, v, on) {
		if (st.type !== 'multi') {
			S.answers[st.id] = v;
			return;
		}
		var cur = Array.isArray(S.answers[st.id]) ? S.answers[st.id].slice() : [];
		var excl = st.exclusive || [];
		if (on) {
			if (excl.indexOf(v) !== -1) {
				cur.forEach(clearNeeds);
				cur = [v];
			} else {
				cur = cur.filter(function (x) { return excl.indexOf(x) === -1; });
				cur.push(v);
			}
		} else {
			cur = cur.filter(function (x) { return x !== v; });
			clearNeeds(v);
		}
		if (cur.length) {
			S.answers[st.id] = cur;
		} else {
			delete S.answers[st.id];
		}
		pendingFocus = 'o-' + st.id + '-' + v;
		S.error = '';
		render();
		var rev = el.body.querySelector('[data-kca-reveal="' + v + '"]');
		if (on && rev) {
			say('Flere felt vises under valget.');
		}
	}

	function validateQuestion() {
		var st = steps()[S.qi];
		var n = guests();
		if (st.type === 'number' && st.required && int(S.answers[st.id]) < (st.min || 1)) {
			return 'Skriv inn omtrent hvor mange dere er.';
		}
		if (st.type === 'pair' && st.required && int(S.answers.adults) + int(S.answers.children) < 1) {
			return 'Skriv inn omtrent hvor mange gjester dere blir.';
		}
		if (st.type === 'multi') {
			var cur = Array.isArray(S.answers[st.id]) ? S.answers[st.id] : [];
			if (cur.indexOf('allergies') !== -1) {
				if (!S.needs.allergy_text) {
					return 'Skriv hvilke allergier eller behov det gjelder.';
				}
				if (!S.needs.allergy_count) {
					return 'Skriv hvor mange gjester allergien gjelder.';
				}
				if (n && S.needs.allergy_count > n) {
					return 'Antallet med allergi kan ikke være høyere enn antall gjester (' + n + ').';
				}
			}
			if (cur.indexOf('vegetarian') !== -1 && n && S.needs.veg_count > n) {
				return 'Antall vegetarianere kan ikke være høyere enn antall gjester (' + n + ').';
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------- */
	/* S2 packages (neutral, stable order)                               */
	/* ---------------------------------------------------------------- */

	function renderPackages() {
		var n = guests();
		var have = persons();
		var wrap = h('div', { className: 'kca-pkgs' });
		wrap.appendChild(h('p', { className: 'kca-intro', text: 'Pakkene er grunnmaten. Fordel porsjonene fritt – én pakke til alle, eller en blanding.' }));
		if (S.error) {
			wrap.appendChild(errorBox(S.error));
		}
		var counter = n ? have + ' av ' + n + ' gjester dekket' : have + ' ' + (have === 1 ? 'person' : 'personer') + ' valgt';
		wrap.appendChild(h('p', { className: 'kca-counter' + (n && have >= n ? ' is-ok' : ''), text: counter }));
		PKGS.forEach(function (p) {
			var q = S.qty[p.id] || 0;
			wrap.appendChild(h('article', { className: 'kca-pkg' + (q ? ' is-selected' : '') }, [
				p.img ? h('img', { className: 'kca-pkg__img', src: p.img, alt: '', loading: 'lazy' }) : null,
				h('div', { className: 'kca-pkg__body' }, [
					h('h3', { text: p.name }),
					h('p', { className: 'kca-price', text: priceLine(p) }),
					p.desc ? h('p', { className: 'kca-desc', text: p.desc }) : null,
					h('div', { className: 'kca-pkg__row' }, [
						stepper(p, 'pkg'),
						n && q !== n ? h('button', {
							type: 'button', className: 'kca-link', 'data-kca-key': 'all-' + p.id,
							on: { click: function () { PKGS.forEach(function (o) { setQty(o.id, o.id === p.id ? n : 0); }); pendingFocus = 'inc-pkg-' + p.id; say('Alle ' + n + ' på ' + p.name + '.'); render(); } }
						}, ['Alle ' + n + ' på denne']) : null
					])
				])
			]));
		});
		if (have > 0 && have < MIN_PERSONS) {
			wrap.appendChild(h('p', { className: 'kca-warn', text: 'Minimum ' + MIN_PERSONS + ' personer totalt. Dere har valgt ' + have + '.' }));
		} else if (n && have > n) {
			wrap.appendChild(h('p', { className: 'kca-info', text: 'Dere har valgt flere porsjoner enn gjester (' + have + ' av ' + n + ').' }));
		} else if (n && have > 0 && have < n) {
			wrap.appendChild(h('p', { className: 'kca-info', text: (n - have) + ' gjester har ikke porsjon ennå.' }));
		}
		return wrap;
	}

	function validatePackages() {
		var have = persons();
		if (!have) {
			return 'Velg minst én pakke.';
		}
		if (have < MIN_PERSONS) {
			return 'Minste bestilling er ' + MIN_PERSONS + ' personer totalt. Dere har valgt ' + have + '.';
		}
		return '';
	}

	/* ---------------------------------------------------------------- */
	/* S3 add-ons: max 3 suggestions + the whole menu (D7)               */
	/* ---------------------------------------------------------------- */

	function addonRow(p, ctx) {
		return h('div', { className: 'kca-addon' + (S.qty[p.id] ? ' is-selected' : '') }, [
			h('div', { className: 'kca-addon__text' }, [
				h('span', { className: 'kca-addon__name', text: p.name }),
				h('span', { className: 'kca-price', text: priceLine(p) + (p.min > 1 ? ' · min. ' + p.min : '') })
			]),
			stepper(p, ctx)
		]);
	}

	function renderAddons() {
		var wrap = h('div', { className: 'kca-adds' });
		if (!ADDS.length) {
			wrap.appendChild(h('p', { className: 'kca-intro', text: 'Ingen tillegg er tilgjengelige akkurat nå. Gå videre.' }));
			return wrap;
		}
		var sug = suggestions();
		wrap.appendChild(h('h3', { className: 'kca-h', text: 'Anbefalt for dere' }));
		if (sug.list.length) {
			var box = h('div', { className: 'kca-sugs' });
			sug.list.forEach(function (s) {
				var p = s.p;
				box.appendChild(h('article', { className: 'kca-sug' }, [
					p.img ? h('img', { className: 'kca-sug__img', src: p.img, alt: '', loading: 'lazy' }) : null,
					h('div', { className: 'kca-sug__text' }, [
						h('p', { className: 'kca-sug__why', text: s.reason }),
						h('h4', { text: p.name }),
						h('p', { className: 'kca-price', text: priceLine(p) }),
						p.desc ? h('p', { className: 'kca-desc', text: p.desc }) : null
					]),
					h('button', {
						type: 'button', className: 'kca-btn kca-btn--add', 'data-kca-key': 'add-' + p.id,
						on: { click: function () { setQty(p.id, s.qty); pendingFocus = 'inc-sel-' + p.id; say('Lagt til: ' + s.qty + ' ' + unitLabel(p, s.qty) + ' ' + p.name + '.'); render(); } }
					}, ['Legg til ' + s.qty + ' ' + unitLabel(p, s.qty) + ' · ' + money(p.price * s.qty)])
				]));
			});
			wrap.appendChild(box);
		} else {
			wrap.appendChild(h('p', { className: 'kca-info', text: 'Ingen flere forslag – se hele tilleggsmenyen under.' }));
		}
		sug.notes.forEach(function (t) {
			wrap.appendChild(h('p', { className: 'kca-info', text: t }));
		});

		var chosen = ADDS.filter(function (p) { return S.qty[p.id]; });
		if (chosen.length) {
			wrap.appendChild(h('h3', { className: 'kca-h', text: 'Valgte tillegg' }));
			var sel = h('div', { className: 'kca-addon-list' });
			chosen.forEach(function (p) { sel.appendChild(addonRow(p, 'sel')); });
			wrap.appendChild(sel);
		}

		var groups = {};
		var order = [];
		ADDS.forEach(function (p) {
			if (!groups[p.group]) {
				groups[p.group] = [];
				order.push(p.group);
			}
			groups[p.group].push(p);
		});
		var all = h('details', { className: 'kca-menu', open: S.menuOpen || null, on: { toggle: function () { S.menuOpen = all.open; } } }, [
			h('summary', { 'data-kca-key': 'menu', text: 'Se hele tilleggsmenyen (' + ADDS.length + ' retter)' })
		]);
		order.forEach(function (g) {
			var key = 'grp-' + g;
			S.groupsOpen = S.groupsOpen || {};
			var count = groups[g].filter(function (p) { return S.qty[p.id]; }).length;
			var d = h('details', { className: 'kca-group', open: S.groupsOpen[g] || null, on: { toggle: function () { S.groupsOpen[g] = d.open; } } }, [
				h('summary', { 'data-kca-key': key, text: g + (count ? ' (' + count + ' valgt)' : '') })
			]);
			var list = h('div', { className: 'kca-addon-list' });
			groups[g].forEach(function (p) { list.appendChild(addonRow(p, 'menu')); });
			d.appendChild(list);
			all.appendChild(d);
		});
		wrap.appendChild(all);
		return wrap;
	}

	/* ---------------------------------------------------------------- */
	/* S4 review + confirm                                               */
	/* ---------------------------------------------------------------- */

	function answerRows() {
		var rows = [];
		var c = cfg();
		if (!c) {
			return rows;
		}
		rows.push(['Anledning', c.label]);
		c.steps.forEach(function (st) {
			if (st.type === 'pair') {
				var parts = Object.keys(st.fields).filter(function (f) { return S.answers[f] !== undefined; }).map(function (f) { return st.fields[f].label + ': ' + S.answers[f]; });
				if (parts.length) {
					rows.push([st.label, parts.join(', ')]);
				}
				return;
			}
			var v = S.answers[st.id];
			if (v === undefined) {
				return;
			}
			if (st.type === 'number') {
				rows.push([st.label, String(v)]);
			} else {
				rows.push([st.label, (Array.isArray(v) ? v : [v]).map(function (x) { return st.options[x] || x; }).join(', ')]);
			}
		});
		if (S.needs.veg_count) {
			rows.push(['Vegetar', S.needs.veg_count + ' personer']);
		}
		return rows;
	}

	function lineList(list) {
		var ul = h('ul', { className: 'kca-lines' });
		list.forEach(function (p) {
			var q = S.qty[p.id];
			ul.appendChild(h('li', {}, [
				h('span', { text: q + ' ' + unitLabel(p, q) + ' ' + p.name }),
				h('span', { className: 'kca-lines__sum', text: money(p.price * q) })
			]));
		});
		return ul;
	}

	function editBtn(label, screen) {
		return h('button', {
			type: 'button', className: 'kca-link', 'data-kca-key': 'edit-' + screen,
			on: { click: function () { S.error = ''; S.screen = screen; S.qi = 0; render(true); } }
		}, [label]);
	}

	function renderReview() {
		var wrap = h('div', { className: 'kca-review' });
		if (S.error) {
			wrap.appendChild(errorBox(S.error));
		}
		var dl = h('dl', { className: 'kca-dl' });
		answerRows().forEach(function (r) {
			dl.appendChild(h('dt', { text: r[0] }));
			dl.appendChild(h('dd', { text: r[1] }));
		});
		wrap.appendChild(h('section', { className: 'kca-block' }, [h('div', { className: 'kca-block__head' }, [h('h3', { text: 'Svarene dine' }), editBtn('Endre svar', 'q')]), dl]));

		var pk = PKGS.filter(function (p) { return S.qty[p.id]; });
		wrap.appendChild(h('section', { className: 'kca-block' }, [
			h('div', { className: 'kca-block__head' }, [h('h3', { text: 'Pakker' }), editBtn('Endre pakker', 'pkg')]),
			pk.length ? lineList(pk) : h('p', { className: 'kca-warn', text: 'Ingen pakker valgt.' }),
			h('p', { className: 'kca-persons', text: persons() + ' personer totalt (minimum ' + MIN_PERSONS + ')' })
		]));
		var ad = ADDS.filter(function (p) { return S.qty[p.id]; });
		wrap.appendChild(h('section', { className: 'kca-block' }, [
			h('div', { className: 'kca-block__head' }, [h('h3', { text: 'Tillegg' }), editBtn('Endre tillegg', 'add')]),
			ad.length ? lineList(ad) : h('p', { className: 'kca-hint', text: 'Ingen tillegg.' })
		]));
		wrap.appendChild(h('p', { className: 'kca-grand' }, [h('span', { text: 'Foreløpig pris' }), h('strong', { text: money(total()) })]));

		var auto = autoNote();
		if (auto) {
			wrap.appendChild(h('section', { className: 'kca-block kca-block--note' }, [
				h('h3', { text: 'Dette sender vi med til Kebabish' }),
				h('p', { className: 'kca-pre', text: auto }),
				h('p', { className: 'kca-hint', text: 'Teksten legges i kommentarfeltet i neste steg. Du kan endre den der.' })
			]));
		}
		var al = allergyLines();
		if (al.length) {
			var box = h('div', { className: 'kca-allergy' });
			al.forEach(function (t) { box.appendChild(h('p', { text: t })); });
			wrap.appendChild(box);
		}
		wrap.appendChild(h('p', { className: 'kca-hint', text: 'Prisen er foreløpig. Kebabish går gjennom bestillingen før du betaler.' }));
		return wrap;
	}

	/* ---------------------------------------------------------------- */
	/* S5 contact (server-rendered fields)                               */
	/* ---------------------------------------------------------------- */

	function fillContact() {
		if (el.allergy) {
			while (el.allergy.firstChild) {
				el.allergy.removeChild(el.allergy.firstChild);
			}
			var al = allergyLines();
			al.forEach(function (t) { el.allergy.appendChild(h('p', { text: t })); });
			el.allergy.hidden = !al.length;
		}
		applyPickup();
	}

	function validateContact() {
		var bad = [];
		function chk(name, ok, msg) {
			var f = form.querySelector('[name="' + name + '"]');
			if (!f) {
				return;
			}
			if (ok(f.value.trim())) {
				f.removeAttribute('aria-invalid');
			} else {
				f.setAttribute('aria-invalid', 'true');
				bad.push({ f: f, msg: msg });
			}
		}
		chk('kc_date', function (v) { return /^\d{4}-\d{2}-\d{2}$/.test(v); }, 'Velg dato for henting.');
		chk('kc_time', function (v) { return v !== ''; }, 'Velg hentetid.');
		chk('kc_name', function (v) { return v.length >= 2; }, 'Skriv inn navn.');
		chk('kc_phone', function (v) { var d = v.replace(/\D+/g, ''); return d.length >= 8 && d.length <= 15; }, 'Skriv inn et gyldig telefonnummer.');
		chk('kc_email', function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }, 'Skriv inn en gyldig e-postadresse.');
		var box = el.contact.querySelector('[data-kca-client-err]');
		if (box) {
			box.parentNode.removeChild(box);
		}
		if (bad.length) {
			box = h('div', { className: 'kco-error kco-error--block', role: 'alert', 'data-kca-client-err': '1' }, [
				h('strong', { text: 'Sjekk feltene:' }),
				h('ul', {}, bad.map(function (b) { return h('li', { text: b.msg }); }))
			]);
			el.contact.insertBefore(box, el.contact.firstChild);
			bad[0].f.focus();
			return false;
		}
		return true;
	}

	/* ---------------------------------------------------------------- */
	/* Shell: render, navigation, open/close                             */
	/* ---------------------------------------------------------------- */

	function title() {
		switch (S.screen) {
			case 'q': return steps()[S.qi] ? steps()[S.qi].label : '';
			case 'pkg': return 'Velg pakker';
			case 'add': return 'Tillegg';
			case 'review': return 'Se over bestillingen';
			default: return 'Kontakt og henting';
		}
	}

	function render(focusTitle) {
		var key = pendingFocus;
		pendingFocus = null;
		if (!key && document.activeElement && el.body.contains(document.activeElement)) {
			key = document.activeElement.getAttribute('data-kca-key');
		}
		var scroll = el.body.scrollTop;
		var idx = SCREENS.indexOf(S.screen);
		var c = cfg();
		dlg.setAttribute('data-tone', c ? c.tone : '');
		dlg.setAttribute('data-screen', S.screen);
		el.kicker.textContent = c ? c.label : '';
		el.title.textContent = title();

		while (el.steps.firstChild) {
			el.steps.removeChild(el.steps.firstChild);
		}
		STEP_LABELS.forEach(function (l, i) {
			el.steps.appendChild(h('li', {
				className: i < idx ? 'is-done' : (i === idx ? 'is-current' : ''),
				'aria-current': i === idx ? 'step' : null
			}, [h('span', { className: 'kca-sr', text: i < idx ? 'Ferdig: ' : '' }), l]));
		});

		var isContact = S.screen === 'contact';
		el.contact.hidden = !isContact;
		el.body.hidden = isContact;
		while (el.body.firstChild) {
			el.body.removeChild(el.body.firstChild);
		}
		var view;
		switch (S.screen) {
			case 'q': view = renderQuestion(); break;
			case 'pkg': view = renderPackages(); break;
			case 'add': view = renderAddons(); break;
			case 'review': view = renderReview(); break;
			default: view = null; fillContact();
		}
		if (view) {
			el.body.appendChild(view);
		}

		el.back.hidden = S.screen === 'q' && S.qi === 0;
		el.next.type = isContact ? 'submit' : 'button';
		el.next.disabled = submitting;
		var nextText = 'Fortsett';
		if (S.screen === 'add') {
			nextText = ADDS.some(function (p) { return S.qty[p.id]; }) ? 'Videre' : 'Nei takk, videre';
		} else if (S.screen === 'review') {
			nextText = 'Bekreft valgene';
		} else if (isContact) {
			nextText = submitting ? 'Sender …' : 'Send bestilling';
		}
		el.next.textContent = nextText;
		var t = total();
		el.total.textContent = t > 0 ? 'Foreløpig ' + money(t) : '';

		if (focusTitle) {
			el.body.scrollTop = 0;
			el.contact.scrollTop = 0;
			el.title.focus();
			return;
		}
		el.body.scrollTop = scroll;
		if (key) {
			var f = dlg.querySelector('[data-kca-key="' + key + '"]');
			if (f && !f.disabled) {
				f.focus();
			} else if (f) {
				var alt = dlg.querySelector('[data-kca-key="' + key.replace(/^dec-/, 'inc-') + '"]');
				if (alt) {
					alt.focus();
				}
			}
		}
	}

	function go(screen, qi) {
		S.error = '';
		S.screen = screen;
		S.qi = qi || 0;
		render(true);
	}

	function next() {
		var err = '';
		switch (S.screen) {
			case 'q':
				err = validateQuestion();
				if (!err) {
					if (S.qi < steps().length - 1) {
						return go('q', S.qi + 1);
					}
					return go('pkg');
				}
				break;
			case 'pkg':
				err = validatePackages();
				if (!err) {
					return go('add');
				}
				break;
			case 'add':
				return go('review');
			case 'review':
				err = validatePackages();
				if (!err) {
					applyNote();
					writeHidden();
					return go('contact');
				}
				break;
		}
		S.error = err;
		render();
		var box = el.body.querySelector('.kca-err');
		if (box) {
			box.setAttribute('tabindex', '-1');
			box.focus();
		}
	}

	function back() {
		S.error = '';
		switch (S.screen) {
			case 'q': return S.qi > 0 ? go('q', S.qi - 1) : null;
			case 'pkg': return go('q', Math.max(0, steps().length - 1));
			case 'add': return go('pkg');
			case 'review': return go('add');
			default: return go('review');
		}
	}

	function openDialog() {
		if (!dlg.open) {
			if (typeof dlg.showModal === 'function') {
				dlg.showModal();
			} else {
				dlg.setAttribute('open', '');
			}
		}
		document.documentElement.classList.add('kca-lock');
		if (el.resume) {
			el.resume.hidden = true;
		}
	}

	function closeDialog() {
		if (typeof dlg.close === 'function' && dlg.open) {
			dlg.close();
		} else {
			dlg.removeAttribute('open');
			onClosed();
		}
	}

	function onClosed() {
		document.documentElement.classList.remove('kca-lock');
		if (el.resume && S.occ) {
			el.resume.hidden = false;
		}
	}

	function start(occ) {
		if (!OCC[occ]) {
			return;
		}
		if (S.occ !== occ) {
			S.occ = occ;
			S.answers = {};
			S.needs = {};
			S.shown = [];
			S.screen = 'q';
			S.qi = 0;
			S.error = '';
			writeHidden();
		}
		openDialog();
		render(true);
	}

	/* ---------------------------------------------------------------- */
	/* Wiring                                                            */
	/* ---------------------------------------------------------------- */

	document.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('[data-kc-occasion]') : null;
		if (!a || dlg.contains(a)) {
			return;
		}
		e.preventDefault();
		start(a.getAttribute('data-kc-occasion'));
	});

	el.next.addEventListener('click', function (e) {
		if (S.screen === 'contact') {
			return; // native submit -> form submit handler
		}
		e.preventDefault();
		next();
	});
	el.back.addEventListener('click', back);
	el.close.addEventListener('click', closeDialog);
	dlg.addEventListener('close', onClosed);
	dlg.addEventListener('cancel', function (e) {
		if (submitting) {
			e.preventDefault();
		}
	});
	dlg.addEventListener('keydown', function (e) {
		// Enter in a question input moves on (never submits the order form).
		if (e.key === 'Enter' && S.screen !== 'contact' && e.target.tagName === 'INPUT' && e.target.getAttribute('form') === 'kca-none' && e.target.type !== 'checkbox' && e.target.type !== 'radio') {
			e.preventDefault();
			if (S.screen === 'q') {
				next();
			}
		}
	});

	form.addEventListener('submit', function (e) {
		if (submitting) {
			e.preventDefault();
			return;
		}
		if (S.screen !== 'contact' || validatePackages() || !validateContact()) {
			e.preventDefault();
			if (S.screen !== 'contact') {
				next();
			}
			return;
		}
		writeHidden();
		submitting = true;
		el.next.disabled = true;
		el.next.textContent = 'Sender …';
	});

	window.addEventListener('pageshow', function (e) {
		if (e.persisted) {
			submitting = false;
			el.next.disabled = false;
			if (S.screen === 'contact') {
				el.next.textContent = 'Send bestilling';
			}
		}
	});

	if (el.resume) {
		var rb = el.resume.querySelector('[data-kca-resume-btn]');
		if (rb) {
			rb.addEventListener('click', function () {
				openDialog();
				render(true);
			});
		}
	}
	if (el.start) {
		el.start.hidden = false;
	}

	/* ---------------------------------------------------------------- */
	/* Boot                                                              */
	/* ---------------------------------------------------------------- */

	restore();
	if (BOOT.reopen && S.occ) {
		S.screen = BOOT.reopen === 'packages' ? 'pkg' : 'contact';
		S.error = BOOT.reopen === 'packages' ? (BOOT.linesError || '') : '';
		openDialog();
		render(true);
		var errs = document.getElementById('kco-errors');
		if (S.screen === 'contact' && errs) {
			errs.focus();
		}
	} else if (BOOT.reopen) {
		// Answers lost (should not happen): start over at the questions, keep chosen quantities.
		start(Object.keys(OCC)[0]);
	} else if (BOOT.autostart && OCC[BOOT.autostart]) {
		start(BOOT.autostart);
	} else {
		writeHidden();
	}

	// Test hook (read-only snapshot) for automated browser tests.
	window.kcaDebug = function () {
		return JSON.parse(JSON.stringify({ occ: S.occ, answers: S.answers, needs: S.needs, qty: S.qty, shown: S.shown, screen: S.screen, qi: S.qi }));
	};
}());
