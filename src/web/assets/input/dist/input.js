/**
 * Recurrence field input.
 *
 * Keeps one JSON state object in the hidden input. Every change re-serializes
 * it, fires `change` so Craft's draft autosave notices, and asks the server for
 * a fresh preview. The preview is rendered by the same PHP code the front end
 * uses, so what editors see is what the site lists.
 */
(function () {
  const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
  const ORDINALS = ['first', 'second', 'third', 'fourth', 'last'];
  const UNITS = {
    daily: ['day', 'days'],
    weekly: ['week', 'weeks'],
    monthly: ['month', 'months'],
    yearly: ['year', 'years'],
  };
  const t = (msg, params) => Craft.t('recurrence', msg, params);

  // Values are local wall-clock strings, `YYYY-MM-DD` or `YYYY-MM-DDTHH:MM`,
  // exactly as stored. Dates are only built for day arithmetic, in the
  // browser's zone, and are formatted straight back to strings.
  const pad = (n) => String(n).padStart(2, '0');
  const parseDay = (value) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  };
  const formatDay = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const splitLocal = (value) => {
    const [date = '', time = ''] = (value || '').split('T');
    return {date, time};
  };
  // JS weeks start on Sunday (0); RFC 5545 codes start on Monday.
  const weekdayCode = (d) => WEEKDAYS[(d.getDay() + 6) % 7];

  // Craft's own pickers, as Date fields use: a jQuery UI datepicker and a
  // jquery-timepicker showing the editor's locale format, or the browser's
  // native inputs on phones (Craft decides). The readers and writers below
  // convert to and from the stored `YYYY-MM-DD` / `HH:MM` either way, never
  // by parsing the displayed text ourselves.
  // `hasOuterContainer` skips Craft's per-input `.datetimewrapper` and its
  // clear button: list rows have their own remove button, and the main
  // inputs share one wrapper per date/time pair, as Date fields do.
  const createPicker = (kind, {id, label, field} = {}) => {
    const options = {id, hasOuterContainer: true};
    const $wrap = kind === 'date' ? Craft.ui.createDateInput(options) : Craft.ui.createTimeInput(options);
    const input = $wrap.find('input')[0];
    if (label) {
      input.setAttribute('aria-label', label);
    }
    if (field) {
      input.dataset.field = field;
    }
    return {$wrap, input};
  };
  // Whether Craft rendered a native browser input (phones) rather than a jQuery picker.
  const isNative = (input) => input.type === 'date' || input.type === 'time';
  // Readers return the stored format (`YYYY-MM-DD`, `HH:MM`) or null when empty,
  // asking the picker for its value rather than parsing the localized text.
  const readDate = (input) => {
    if (!input || !input.value.trim()) {
      return null;
    }
    if (isNative(input)) {
      return input.value;
    }
    const d = $(input).datepicker('getDate');
    return d ? formatDay(d) : null;
  };
  const readTime = (input) => {
    if (!input || !input.value.trim()) {
      return null;
    }
    if (isNative(input)) {
      return input.value.slice(0, 5);
    }
    const d = $(input).timepicker('getTime');
    return d ? `${pad(d.getHours())}:${pad(d.getMinutes())}` : null;
  };
  // Writers skip unchanged values so re-rendering doesn't disturb an input
  // the editor is using. Neither picker fires `change` when set from code.
  const writeDate = (input, value) => {
    if (readDate(input) === (value || null)) {
      return;
    }
    if (isNative(input)) {
      input.value = value || '';
      input.classList.toggle('empty-value', !value);
    } else {
      $(input).datepicker('setDate', value ? parseDay(value) : null);
    }
  };
  const writeTime = (input, value) => {
    if (readTime(input) === (value || null)) {
      return;
    }
    if (isNative(input) || !value) {
      input.value = value || '';
      input.classList.toggle('empty-value', isNative(input) && !value);
    } else {
      const [h, m] = value.split(':').map(Number);
      $(input).timepicker('setTime', h * 3600 + m * 60);
    }
  };
  // Pickers in re-rendered lists are thrown away; the timepicker keeps its
  // dropdown on <body>, so remove it with them.
  const destroyPickers = (el) => {
    $(el).find('.ui-timepicker-input').timepicker('remove');
  };

  Craft.RecurrenceInput = class {
    /**
     * @param {object} config From fields\Recurrence::inputHtml(): `id` (root element),
     *   `value` (stored JSON or null), `tz`, `defaultDuration` (minutes),
     *   `fieldId`, `previewAction` and `previewLimit`.
     */
    constructor(config) {
      this.config = config;
      this.root = document.getElementById(config.id);
      if (!this.root) {
        return;
      }
      // Elements marked `data-ref="name"` in input.twig, as this.refs.name.
      this.refs = {};
      this.root.querySelectorAll('[data-ref]').forEach((el) => {
        this.refs[el.dataset.ref] = el;
      });
      // Replace the `data-picker` placeholders with pickers. The input becomes
      // this.refs.name and its wrapper `{name}Wrap` (for showing and hiding),
      // unless the template named a wrapper itself.
      this.root.querySelectorAll('[data-picker]').forEach((el) => {
        const name = el.dataset.picker;
        const {$wrap, input} = createPicker(el.dataset.kind, {id: `${config.id}-${name}`, label: el.dataset.label});
        $(el).replaceWith($wrap);
        this.refs[name] = input;
        this.refs[`${name}Wrap`] ??= $wrap[0];
      });
      this.root.querySelectorAll('label[data-for]').forEach((label) => {
        label.htmlFor = this.refs[label.dataset.for].id;
      });
      this.limit = config.previewLimit; // preview rows; "Show more" adds as many again
      this.rowMenus = []; // Garnish.DisclosureMenu per preview row, destroyed on re-render
      this.requestId = 0; // drops out-of-order preview responses
      this.state = this.normalize(config.value);
      this.bind();
      this.render();
      // Craft's clear button for each date/time pair, added once values are in.
      $(this.root).find('[data-datetime]').datetime();
      this.refreshPreview();
    }

    /** The full state shape with defaults filled in. */
    normalize(value) {
      return Object.assign(
        {
          tz: this.config.tz,
          start: null,
          end: null,
          allDay: false,
          freq: 'none',
          interval: 1,
          byDay: [],
          monthlyMode: 'day',
          ends: 'never',
          until: null,
          count: null,
          rdates: [],
          exdates: [],
          overrides: {},
        },
        value || {},
        // PHP encodes an empty map as `[]`; keep it an object.
        {overrides: Array.isArray(value?.overrides) ? {} : value?.overrides || {}},
      );
    }

    // Events
    // -------------------------------------------------------------------------

    bind() {
      const r = this.refs;
      // Each control updates the state, then commit() saves and re-renders.
      // Bound with jQuery: the datepicker announces picks with a jQuery-only
      // `change`, which addEventListener never sees.
      const onChange = (el, fn) => {
        $(el).on('change', () => {
          if (this.syncing) {
            return;
          }
          fn();
          this.commit();
        });
      };

      onChange(r.startDate, () => this.setStart());
      onChange(r.startTime, () => this.setStart());
      onChange(r.endDate, () => this.setEnd());
      onChange(r.endTime, () => this.setEnd());
      // Toggling all-day converts every stored value between the two formats:
      // dropping times, or adding default ones back.
      onChange(r.allDay, () => {
        const s = this.state;
        s.allDay = r.allDay.checked;
        const start = splitLocal(s.start);
        const end = splitLocal(s.end);
        if (s.allDay) {
          s.start = start.date || null;
          s.end = end.date || start.date || null;
        } else {
          s.start = start.date ? `${start.date}T${start.time || '09:00'}` : null;
          s.end = end.date ? `${end.date}T${end.time || '10:00'}` : null;
        }
        // Extra dates and reschedules follow the switch too: dates only when
        // all-day, otherwise the date at the main start time.
        const startTime = splitLocal(s.start).time || '00:00';
        const convert = (d) => (d ? (s.allDay ? splitLocal(d).date : `${splitLocal(d).date}T${startTime}`) : d);
        s.rdates = s.rdates.map(convert);
        Object.values(s.overrides).forEach((o) => {
          o.start = convert(o.start);
          o.end = convert(o.end);
        });
      });
      // Switching to weekly preselects the start's weekday.
      onChange(r.freq, () => {
        this.state.freq = r.freq.value;
        if (this.state.freq === 'weekly' && !this.state.byDay.length) {
          const start = parseDay(this.state.start);
          if (start) {
            this.state.byDay = [weekdayCode(start)];
          }
        }
      });
      onChange(r.interval, () => {
        this.state.interval = Math.max(1, parseInt(r.interval.value, 10) || 1);
      });
      onChange(r.monthlyMode, () => {
        this.state.monthlyMode = r.monthlyMode.value;
      });
      onChange(r.ends, () => {
        this.state.ends = r.ends.value;
        if (this.state.ends === 'count' && !this.state.count) {
          this.state.count = 10;
        }
      });
      onChange(r.until, () => {
        this.state.until = readDate(r.until);
      });
      onChange(r.count, () => {
        this.state.count = Math.max(1, parseInt(r.count.value, 10) || 1);
      });

      // Weekday toggle buttons, kept in Monday-first order.
      this.root.querySelectorAll('[data-day]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const day = btn.dataset.day;
          const days = new Set(this.state.byDay);
          days.has(day) ? days.delete(day) : days.add(day);
          this.state.byDay = WEEKDAYS.filter((d) => days.has(d));
          this.commit();
        });
      });

      // Buttons are re-rendered often, so clicks are delegated. Row actions
      // come through the Actions menus instead (see initRowMenus()), but call
      // handleAction() the same way. Preview rows carry the original key.
      this.root.addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-action]');
        if (!btn || !this.root.contains(btn)) {
          return;
        }
        const row = btn.closest('[data-key]');
        this.handleAction(btn.dataset.action, btn, row);
      });

      // Inputs in the extra/skipped date lists, which are re-rendered often.
      $(this.root).on('change', '[data-list] [data-field]', (ev) => {
        this.readList(ev.currentTarget.closest('[data-list]').dataset.list);
        this.commit();
      });
    }

    /** Runs a `data-action` button. `row` is the preview row it's in, if any. */
    handleAction(action, btn, row) {
      const s = this.state;
      const key = row?.dataset.key;
      // The override for a key, created on first use.
      const override = (k) => (s.overrides[k] = s.overrides[k] || {});

      switch (action) {
        // A new extra date, a week after the last one, at the series' time.
        case 'add-rdate': {
          const time = splitLocal(s.start).time || '09:00';
          const base = parseDay(s.rdates[s.rdates.length - 1] || s.start) || new Date();
          base.setDate(base.getDate() + 7);
          s.rdates.push(s.allDay ? formatDay(base) : `${formatDay(base)}T${time}`);
          break;
        }
        // A new skipped date, prefilled with the next upcoming date.
        case 'add-exdate': {
          const next = this.root.querySelector('.recurrence-preview__row:not(.is-cancelled)');
          s.exdates.push(next ? next.dataset.key.slice(0, 10) : formatDay(new Date()));
          break;
        }
        case 'remove-item': {
          const list = btn.closest('[data-list]').dataset.list;
          const index = [...btn.closest('ul').children].indexOf(btn.closest('li'));
          s[list].splice(index, 1);
          break;
        }
        case 'toggle-cancel':
          override(key).cancelled = !s.overrides[key]?.cancelled;
          break;
        // Skip removes the date from the series outright; any changes to it go too.
        case 'skip':
          s.exdates.push(key);
          delete s.overrides[key];
          break;
        // Edited in a modal, which commits on save; nothing to do here yet.
        case 'note':
          this.openNoteModal(row, btn);
          return;
        case 'reschedule':
          this.openRescheduler(row);
          return;
        case 'save-reschedule': {
          const editor = row.querySelector('.recurrence-preview__editor');
          // Read the inline editor's pickers back into the override.
          const field = (name) => editor.querySelector(`[data-field="${name}"]`);
          const value = (name) => (name.endsWith('Date') ? readDate(field(name)) : readTime(field(name))) || '';
          const o = override(key);
          if (s.allDay) {
            o.start = value('startDate') || undefined;
            o.end = value('endDate') || undefined;
          } else {
            o.start = value('startDate') ? `${value('startDate')}T${value('startTime') || '00:00'}` : undefined;
            o.end = value('endDate') ? `${value('endDate')}T${value('endTime') || '00:00'}` : undefined;
          }
          break;
        }
        case 'cancel-reschedule': {
          const editor = row.querySelector('.recurrence-preview__editor');
          destroyPickers(editor);
          editor?.remove();
          return;
        }
        case 'clear-override':
          delete s.overrides[btn.dataset.override];
          break;
        case 'more':
          this.limit += this.config.previewLimit;
          this.refreshPreview();
          return;
        default:
          return;
      }
      this.commit();
    }

    /**
     * A Craft modal for one date's note, laid out like Craft's own dialogs
     * (a form with a body and a right-aligned Cancel/Save footer). Saving a
     * blank note removes it. The modal is torn down once it has faded out.
     */
    openNoteModal(row, trigger) {
      const key = row.dataset.key;
      const date = row.querySelector('.recurrence-preview__date')?.textContent.trim() || key;

      const $form = $('<form/>', {class: 'modal fitted recurrence-note-modal'}).appendTo(Garnish.$bod);
      const $body = $('<div/>', {class: 'body'}).appendTo($form);
      const $field = Craft.ui
        .createTextareaField({
          label: t('Note for {date}', {date}),
          instructions: t('Shown with this date. Leave blank to remove it.'),
          rows: 3,
        })
        .appendTo($body);
      // Set as a value: createTextarea() would parse `value` as HTML.
      const $textarea = $field.find('textarea').val(this.state.overrides[key]?.note || '');

      const $buttons = $('<div/>', {class: 'buttons right'}).appendTo($('<div/>', {class: 'footer'}).appendTo($form));
      const $cancel = $('<button/>', {type: 'button', class: 'btn', text: Craft.t('app', 'Cancel')}).appendTo($buttons);
      Craft.ui.createSubmitButton({label: Craft.t('app', 'Save')}).appendTo($buttons);

      const modal = new Garnish.Modal($form, {
        triggerElement: trigger,
        onShow: () => setTimeout(() => $textarea.trigger('focus')),
        onFadeOut: () => modal.destroy(),
      });

      $cancel.on('click', () => modal.hide());
      $form.on('submit', (e) => {
        e.preventDefault();
        const note = $textarea.val().trim();
        if (note) {
          (this.state.overrides[key] = this.state.overrides[key] || {}).note = note;
        } else if (this.state.overrides[key]) {
          delete this.state.overrides[key].note;
        }
        modal.hide();
        this.commit();
      });
      // Cmd/Ctrl+Enter saves, as in Craft's other text dialogs; plain Enter is a new line.
      $textarea.on('keydown', (e) => {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
          e.preventDefault();
          $form.trigger('submit');
        }
      });
    }

    /** Inline start/end inputs under a preview row, prefilled with its current times. */
    openRescheduler(row) {
      if (row.querySelector('.recurrence-preview__editor')) {
        return;
      }
      const start = splitLocal(row.dataset.start);
      const end = splitLocal(row.dataset.end);
      const allDay = this.state.allDay;
      const editor = document.createElement('div');
      editor.className = 'recurrence-preview__editor flex flex-nowrap';
      // One picker, prefilled, appended to the editor row.
      const add = (kind, field, label, value) => {
        const {$wrap, input} = createPicker(kind, {label, field});
        $wrap.appendTo(editor);
        (kind === 'date' ? writeDate : writeTime)(input, value);
      };
      add('date', 'startDate', t('New start date'), start.date);
      if (!allDay) {
        add('time', 'startTime', t('New start time'), start.time);
      }
      $('<span>–</span>').appendTo(editor);
      add('date', 'endDate', t('New end date'), end.date);
      if (!allDay) {
        add('time', 'endTime', t('New end time'), end.time);
      }
      $(`<button type="button" class="btn small submit" data-action="save-reschedule">${t('Apply')}</button>`).appendTo(editor);
      $(`<button type="button" class="btn small" data-action="cancel-reschedule">${t('Close')}</button>`).appendTo(editor);
      row.appendChild(editor);
      editor.querySelector('input').focus();
    }

    /** Reads the start inputs and moves the end with it. */
    setStart() {
      const r = this.refs;
      const s = this.state;
      const prevStart = s.start;
      const date = readDate(r.startDate);
      if (!date) {
        s.start = null;
        return;
      }
      s.start = s.allDay ? date : `${date}T${readTime(r.startTime) || '09:00'}`;

      // Keep the event's length when the start moves; default it for new values.
      const prev = prevStart && new Date(prevStart.length === 10 ? `${prevStart}T00:00` : prevStart);
      const prevEnd = s.end && new Date(s.end.length === 10 ? `${s.end}T00:00` : s.end);
      const next = new Date(s.start.length === 10 ? `${s.start}T00:00` : s.start);
      const length = prev && prevEnd && prevEnd >= prev ? prevEnd - prev : (s.allDay ? 0 : this.config.defaultDuration * 60000);
      const end = new Date(next.getTime() + length);
      s.end = s.allDay ? formatDay(end) : `${formatDay(end)}T${pad(end.getHours())}:${pad(end.getMinutes())}`;
    }

    /** Reads the end inputs. */
    setEnd() {
      const r = this.refs;
      const date = readDate(r.endDate);
      if (!date) {
        this.state.end = null;
        return;
      }
      this.state.end = this.state.allDay ? date : `${date}T${readTime(r.endTime) || '00:00'}`;
    }

    /** Reads an extra-dates or skipped-dates list back from its inputs. */
    readList(list) {
      const ul = this.refs[list];
      this.state[list] = [...ul.children]
        .map((li) => {
          const date = readDate(li.querySelector('[data-field="date"]'));
          const time = readTime(li.querySelector('[data-field="time"]'));
          if (!date) {
            return null;
          }
          if (list === 'rdates') {
            return this.state.allDay ? date : `${date}T${time || '00:00'}`;
          }
          // Skipped occurrence keys may carry a time suffix (`-1700`).
          return date + (li.dataset.suffix || '');
        })
        .filter(Boolean);
    }

    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Tidies the state, writes it to the hidden input that gets posted, and
     * re-renders. An empty start posts an empty value, clearing the field.
     */
    commit() {
      const s = this.state;
      s.rdates = [...new Set(s.rdates)].sort();
      s.exdates = [...new Set(s.exdates)].sort();
      // Drop unset properties, then overrides left with none.
      for (const [key, o] of Object.entries(s.overrides)) {
        Object.keys(o).forEach((k) => (o[k] === undefined || o[k] === false) && delete o[k]);
        if (!Object.keys(o).length) {
          delete s.overrides[key];
        }
      }

      this.refs.value.value = s.start ? JSON.stringify(s) : '';
      $(this.refs.value).trigger('change'); // jQuery event, which Craft's autosave listens for
      this.render();
      this.refreshPreview();
    }

    /** Syncs every control to the state. */
    render() {
      const r = this.refs;
      const s = this.state;
      const start = splitLocal(s.start);
      const end = splitLocal(s.end);
      const startDay = parseDay(s.start);
      const hasRule = ['daily', 'weekly', 'monthly', 'yearly'].includes(s.freq);

      writeDate(r.startDate, start.date);
      writeTime(r.startTime, start.time);
      writeDate(r.endDate, end.date);
      writeTime(r.endTime, end.time);
      r.startTimeWrap.hidden = r.endTimeWrap.hidden = s.allDay;
      r.allDay.checked = s.allDay;
      r.freq.value = s.freq;
      r.interval.value = s.interval;
      const unit = UNITS[s.freq];
      r.intervalUnit.textContent = unit ? t(s.interval === 1 ? unit[0] : unit[1]) : '';
      r.ends.value = s.ends;
      writeDate(r.until, s.until);
      r.untilWrap.hidden = s.ends !== 'until';
      r.count.value = s.count || '';
      r.countWrap.hidden = s.ends !== 'count';

      this.root.querySelectorAll('[data-day]').forEach((btn) => {
        const on = s.byDay.includes(btn.dataset.day);
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      });

      // Monthly options describe the start date ("on the 2nd Thursday").
      if (startDay) {
        const dayName = startDay.toLocaleDateString(undefined, {weekday: 'long'});
        const nth = Math.floor((startDay.getDate() - 1) / 7);
        const [dayOpt, nthOpt, lastOpt] = r.monthlyMode.options;
        dayOpt.textContent = t('on day {n}', {n: startDay.getDate()});
        nthOpt.textContent = t('on the {nth} {day}', {nth: t(ORDINALS[nth]), day: dayName});
        lastOpt.textContent = t('on the last {day}', {day: dayName});
        nthOpt.hidden = nth === 4; // a fifth weekday is the same as "last"
      }
      // Shown as "last" when the start is a fifth weekday, matching the server.
      r.monthlyMode.value = s.monthlyMode === 'nth' && startDay && startDay.getDate() > 28 ? 'last' : s.monthlyMode;

      // Sections marked `data-show="…"` appear only for the rules they apply to.
      const show = {
        rule: hasRule,
        weekly: s.freq === 'weekly',
        monthly: s.freq === 'monthly',
        extra: s.freq !== 'none',
      };
      this.root.querySelectorAll('[data-show]').forEach((el) => {
        el.hidden = !show[el.dataset.show];
      });
      r.rdatesLabel.textContent = s.freq === 'dates' ? t('More dates') : t('Extra dates');

      this.renderList('rdates', s.rdates, !s.allDay);
      this.renderList('exdates', s.exdates, false);
      this.renderOverrides();
      this.syncClearButtons();
    }

    /**
     * Craft's clear buttons show or hide on `change`, which values set from
     * code don't fire. Fire it with `syncing` set, so our own handlers ignore it.
     */
    syncClearButtons() {
      const $inputs = $(this.root).find('[data-datetime].datetimewrapper input.text');
      if (!$inputs.length) {
        return;
      }
      this.syncing = true;
      $inputs.trigger('change');
      this.syncing = false;
    }

    /**
     * Renders a date list. Skipped dates may be occurrence keys with a time
     * suffix (`2026-12-24-1700`); the suffix rides along on the item and is
     * shown as text, since it names one date rather than a time to edit.
     */
    renderList(list, values, withTime) {
      const ul = this.refs[list];
      destroyPickers(ul);
      ul.innerHTML = '';
      ul.dataset.list = list;
      values.forEach((value) => {
        const {date, time} = splitLocal(value);
        const li = document.createElement('li');
        li.className = 'flex flex-nowrap';
        li.dataset.suffix = date.slice(10);
        const day = createPicker('date', {label: t('Date'), field: 'date'});
        day.$wrap.appendTo(li);
        writeDate(day.input, date.slice(0, 10));
        if (withTime) {
          const clock = createPicker('time', {label: t('Time'), field: 'time'});
          clock.$wrap.appendTo(li);
          writeTime(clock.input, time);
        }
        if (date.length > 10) {
          $('<span class="light"/>').text(t('at {time}', {time: `${date.slice(11, 13)}:${date.slice(13)}`})).appendTo(li);
        }
        $(`<button type="button" class="delete icon" data-action="remove-item" title="${t('Remove')}" aria-label="${t('Remove')}"></button>`).appendTo(li);
        ul.appendChild(li);
      });
    }

    /** Lists every override, including ones beyond the visible preview. */
    renderOverrides() {
      const wrap = this.refs.overrides;
      const entries = Object.entries(this.state.overrides);
      if (!entries.length) {
        wrap.innerHTML = '';
        return;
      }
      // "cancelled, moved to 2026-11-24 18:00, “Snow day”"
      const describe = (o) => [
        o.cancelled ? t('cancelled') : null,
        o.start ? t('moved to {date}', {date: o.start.replace('T', ' ')}) : null,
        o.note ? `“${o.note}”` : null,
      ].filter(Boolean).join(', ');
      wrap.innerHTML = `
        <p class="light smalltext">${t('Changed dates')}</p>
        <ul class="recurrence-input__list">
          ${entries.map(([key, o]) => `
            <li class="flex flex-nowrap">
              <code>${key}</code><span>${Craft.escapeHtml(describe(o))}</span>
              <button type="button" class="delete icon" data-action="clear-override" data-override="${key}" title="${t('Undo changes')}" aria-label="${t('Undo changes')}"></button>
            </li>`).join('')}
        </ul>`;
    }

    /**
     * Gives each preview row an Actions ("⋯") menu, built the way Craft's
     * editable table rows build theirs: a `btn action-btn` trigger and a
     * Garnish.DisclosureMenu, with the destructive item last and in red.
     */
    initRowMenus() {
      const icon = (name) => async () => Craft.ui.icon(name);
      this.refs.preview.querySelectorAll('[data-actions]').forEach((slot) => {
        const row = slot.closest('[data-key]');
        const menuId = `menu-${Math.floor(Math.random() * 1e9)}`;
        const $btn = $('<button/>', {
          type: 'button',
          class: 'btn action-btn',
          'aria-controls': menuId,
          'aria-label': Craft.t('app', 'Actions'),
          'aria-describedby': row.querySelector('.recurrence-preview__date')?.id,
          'data-disclosure-trigger': '',
          'data-icon': 'ellipsis',
        }).appendTo(slot);
        $('<div/>', {id: menuId, class: 'menu menu--disclosure'}).appendTo(slot);

        const menu = new Garnish.DisclosureMenu($btn);
        const run = (action) => () => this.handleAction(action, $btn[0], row);
        menu.addItems([
          slot.hasAttribute('data-cancelled')
            ? {label: t('Restore date'), icon: icon('arrow-rotate-left'), onActivate: run('toggle-cancel')}
            : {label: t('Mark as cancelled'), icon: icon('ban'), onActivate: run('toggle-cancel')},
          {label: t('Reschedule'), icon: icon('calendar'), onActivate: run('reschedule')},
          {label: slot.hasAttribute('data-has-note') ? t('Edit note') : t('Add note'), icon: icon('comment'), onActivate: run('note')},
        ]);
        menu.addHr();
        menu.addItem({label: t('Remove from series'), icon: icon('trash'), destructive: true, onActivate: run('skip')});
        this.rowMenus.push(menu);
      });
    }

    /** Garnish moves each menu to <body> and destroy() leaves it there, so remove it too. */
    destroyRowMenus() {
      this.rowMenus.forEach((menu) => {
        menu.hide();
        menu.destroy();
        menu.$container.remove();
      });
      this.rowMenus = [];
    }

    /** Debounced, so typing in a field doesn't send a request per keystroke. */
    refreshPreview() {
      clearTimeout(this.previewTimer);
      this.previewTimer = setTimeout(() => this.loadPreview(), 250);
    }

    /** Fetches the summary, errors and dates list from PreviewController. */
    async loadPreview() {
      const r = this.refs;
      if (!this.state.start) {
        r.previewWrap.hidden = true;
        r.errors.hidden = true;
        return;
      }
      const request = ++this.requestId;
      try {
        const {data} = await Craft.sendActionRequest('POST', this.config.previewAction, {
          data: {
            fieldId: this.config.fieldId,
            value: JSON.stringify(this.state),
            limit: this.limit,
          },
        });
        if (request !== this.requestId) {
          return; // a newer request is in flight
        }
        r.previewWrap.hidden = false;
        r.summary.textContent = data.summary;
        this.destroyRowMenus();
        r.preview.innerHTML = data.html;
        this.initRowMenus();
        r.errors.innerHTML = (data.errors || []).map((e) => `<li>${Craft.escapeHtml(e)}</li>`).join('');
        r.errors.hidden = !data.errors?.length;
      } catch (e) {
        console.error('Recurrence preview failed', e);
      }
    }
  };
})();
