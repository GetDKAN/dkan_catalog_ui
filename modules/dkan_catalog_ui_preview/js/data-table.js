/**
 * @file
 * Progressive enhancement for the data table.
 *
 * Without this script every control is a link or a GET form to the apply
 * route. With it: links and forms inside the table update the table in place
 * through the fragment route and push history; panels get keyboard and
 * click-outside behaviour; JS-only controls appear (add filter, full screen,
 * row height, operator narrowing). Any failure falls back to navigation.
 */
(function (Drupal, once) {
  'use strict';

  const ROOT = '#dcu-table';
  const DENSITY_KEY = 'dcuTableDensity';
  const DENSITIES = ['compact', 'normal', 'expanded'];

  /**
   * Read the stored row height, or 'normal'.
   */
  function storedDensity() {
    try {
      const value = window.localStorage.getItem(DENSITY_KEY);
      return DENSITIES.includes(value) ? value : 'normal';
    }
    catch (e) {
      return 'normal';
    }
  }

  /**
   * Apply a row height to the root and remember it.
   */
  function applyDensity(root, density, persist) {
    DENSITIES.forEach((d) => root.classList.toggle(`dcu-table--density-${d}`, d === density));
    if (persist) {
      try {
        window.localStorage.setItem(DENSITY_KEY, density);
      }
      catch (e) {
        // Storage unavailable: the choice lasts for the page only.
      }
    }
  }

  /**
   * Canonical URL for the page: the given URL minus the transient panel.
   */
  function canonical(url) {
    const clean = new URL(url, window.location.href);
    clean.searchParams.delete('panel');
    clean.hash = '';
    return clean;
  }

  /**
   * Selector for a panel's own toggle, the fallback focus target.
   */
  function panelToggle(panel) {
    return `#${CSS.escape(panel.id)} > summary`;
  }

  /**
   * Where focus belongs after a submit re-renders the table.
   *
   * @return {string[]}
   *   Selectors to try in order; empty leaves focus on the table root.
   */
  function focusHints(form, submitter) {
    const panel = form.closest('details.dcu-table__panel');
    if (!panel || !panel.id) {
      return [];
    }
    const toggle = panelToggle(panel);
    const id = `#${CSS.escape(panel.id)}`;
    const name = submitter && submitter.name ? submitter.name : '';
    if (name === 'move_up' || name === 'move_down') {
      // The value is a column name, so the button survives the move; at a
      // list boundary it is gone and the toggle takes over.
      return [`${id} [name="${CSS.escape(name)}"][value="${CSS.escape(submitter.value)}"]`, toggle];
    }
    if (name === 'remove' || name === 'reset_filters') {
      // Conditions are reindexed, so the submitted index would now point at
      // another row's Remove button. Land on the list instead.
      return [`${id} .dcu-table__filter-row select`, toggle];
    }
    if (name === 'reset_columns') {
      return [`${id} .dcu-table__column-list input[type="checkbox"]`, toggle];
    }
    // The primary action closes the panel: back to the control that opened it.
    return [toggle];
  }

  /**
   * Focus the first hint that takes focus, else the root itself.
   */
  function focusAfter(root, hints) {
    const found = (hints || []).some((selector) => {
      const el = root.querySelector(selector);
      if (!el) {
        return false;
      }
      el.focus({ preventScroll: false });
      // A hidden or disabled match never becomes activeElement; try the next.
      return document.activeElement === el;
    });
    if (!found) {
      root.focus({ preventScroll: false });
    }
  }

  /**
   * Fetch the fragment for a URL and swap it in.
   *
   * The fragment request keeps the transient `panel` parameter so the
   * acted-on panel stays open; the history entry is the canonical page URL.
   *
   * @param {string[]} [hints]
   *   Focus targets for the new root, tried in order (see focusAfter()).
   *
   * @return {Promise<boolean>}
   *   Resolves FALSE when the caller should fall back to navigation.
   */
  async function load(fullUrl, pageUrl, push, hints) {
    const root = document.querySelector(ROOT);
    const toolbar = root && root.querySelector('[data-dcu-fragment]');
    if (!root || !toolbar) {
      return false;
    }
    const fragmentUrl = new URL(toolbar.dataset.dcuFragment, window.location.href);
    fragmentUrl.search = fullUrl.search;

    root.classList.add('is-loading');
    root.setAttribute('aria-busy', 'true');
    let html;
    try {
      const response = await fetch(fragmentUrl.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      if (!response.ok) {
        return false;
      }
      html = await response.text();
    }
    catch (e) {
      return false;
    }
    finally {
      root.classList.remove('is-loading');
      root.removeAttribute('aria-busy');
    }

    const template = document.createElement('template');
    template.innerHTML = html.trim();
    const next = template.content.querySelector(ROOT);
    if (!next) {
      return false;
    }
    // Leave full screen on the old root so its inert bookkeeping unwinds,
    // then re-enter on the new one.
    const wasFullscreen = root.classList.contains('dcu-table--fullscreen');
    if (wasFullscreen) {
      exitFullscreen(root);
    }
    root.replaceWith(next);
    if (push) {
      window.history.pushState({ dcuTable: true }, '', pageUrl.toString());
    }
    Drupal.attachBehaviors(next.parentNode);
    if (wasFullscreen) {
      enterFullscreen(next);
    }
    focusAfter(next, hints);
    const summary = next.querySelector('.dcu-table__summary');
    Drupal.announce(summary ? summary.textContent.trim() : Drupal.t('Table updated'));
    return true;
  }

  /**
   * Navigate to a page URL, in place when possible.
   *
   * In place the history entry is the canonical URL (no transient panel);
   * a fallback navigation keeps the URL as given, like a no-script submit.
   */
  function go(url, fallback, hints) {
    const fullUrl = new URL(url, window.location.href);
    const pageUrl = canonical(url);
    load(fullUrl, pageUrl, true, hints).then((done) => {
      if (!done) {
        // Navigate to the URL as given, panel and all, so the fallback
        // leaves the same panel open the server would have.
        fallback(fullUrl);
      }
    });
  }

  /**
   * Whether a link inside the table is a table-state link (same path).
   */
  function isStateLink(link) {
    if (!link.href || link.target || link.hasAttribute('download')) {
      return false;
    }
    const url = new URL(link.href, window.location.href);
    return url.origin === window.location.origin && url.pathname === window.location.pathname;
  }

  /**
   * Submit a toolbar form through the apply route and load the result.
   */
  async function submitForm(form, submitter) {
    const params = new URLSearchParams(new FormData(form));
    if (submitter && submitter.name) {
      params.append(submitter.name, submitter.value);
    }
    const applyUrl = new URL(form.action, window.location.href);
    applyUrl.search = params.toString();
    try {
      const response = await fetch(applyUrl.toString(), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      if (!response.ok) {
        throw new Error(response.status);
      }
      const data = await response.json();
      if (!data || !data.url) {
        throw new Error('no url');
      }
      go(data.url, (pageUrl) => { window.location.assign(pageUrl.toString()); }, focusHints(form, submitter));
    }
    catch (e) {
      // Full navigation through the apply route, submitter included.
      window.location.assign(applyUrl.toString());
    }
  }

  /**
   * Close every panel except the given one, returning focus if requested.
   */
  function closePanels(root, except, refocus) {
    root.querySelectorAll('details.dcu-table__panel[open]').forEach((panel) => {
      if (panel !== except) {
        panel.removeAttribute('open');
        if (refocus) {
          const summary = panel.querySelector('summary');
          if (summary) {
            summary.focus();
          }
        }
      }
    });
  }

  /**
   * Narrow a row's operator select to the operators of the chosen column.
   */
  function narrowOperators(form, propertySelect) {
    let sets;
    try {
      sets = JSON.parse(form.dataset.dcuOperators || '{}');
    }
    catch (e) {
      return;
    }
    const option = propertySelect.selectedOptions[0];
    const category = option ? option.dataset.category : '';
    const operatorSelect = form.querySelector(`[name="${propertySelect.name.replace('[property]', '[operator]')}"]`);
    if (!operatorSelect || !category || !sets[category]) {
      return;
    }
    const current = operatorSelect.value;
    operatorSelect.replaceChildren(...sets[category].map((op) => {
      const el = document.createElement('option');
      el.value = op.value;
      el.textContent = op.label;
      el.selected = op.value === current;
      return el;
    }));
  }

  /**
   * Add a blank filter row after the last one (client-side only).
   */
  function addFilterRow(form) {
    const rows = form.querySelectorAll('.dcu-table__filter-row');
    const last = rows[rows.length - 1];
    const max = parseInt(form.dataset.dcuMaxFilters || '10', 10);
    if (!last || rows.length >= max) {
      return;
    }
    const index = rows.length;
    const row = last.cloneNode(true);
    row.classList.add('dcu-table__filter-row--blank');
    row.querySelectorAll('[name]').forEach((field) => {
      field.name = field.name.replace(/\[\d+\]/, `[${index}]`);
      if (field.tagName === 'INPUT') {
        field.value = '';
      }
      else if (field.tagName === 'SELECT') {
        field.selectedIndex = 0;
      }
    });
    row.querySelectorAll('[id]').forEach((el) => { el.id = el.id.replace(/-\d+-/, `-${index}-`); });
    row.querySelectorAll('label[for]').forEach((el) => { el.htmlFor = el.htmlFor.replace(/-\d+-/, `-${index}-`); });
    const remove = row.querySelector('button[name="remove"]');
    if (remove) {
      remove.remove();
    }
    last.after(row);
    const first = row.querySelector('select');
    if (first) {
      first.focus();
    }
  }

  /**
   * Copy the canonical link of the current state to the clipboard.
   */
  function copyLink(button) {
    const status = button.parentNode.querySelector('.dcu-table__copy-status');
    const report = (text) => {
      if (status) {
        status.textContent = text;
      }
      Drupal.announce(text);
    };
    if (!navigator.clipboard) {
      report(Drupal.t('Copy is not available; the link is @url', { '@url': button.dataset.dcuCopy }));
      return;
    }
    navigator.clipboard.writeText(button.dataset.dcuCopy).then(
      () => report(Drupal.t('Link copied')),
      () => report(Drupal.t('Could not copy the link')),
    );
  }

  /**
   * Set a button's visible text without touching its icons.
   */
  function setLabel(button, text) {
    const label = button.querySelector('.dcu-table__button-label');
    if (label) {
      label.textContent = text;
    }
    else {
      button.textContent = text;
    }
  }

  let inertElements = [];

  /**
   * Expand the table over the viewport; everything else becomes inert.
   */
  function enterFullscreen(root) {
    root.classList.add('dcu-table--fullscreen');
    document.body.classList.add('dcu-table-scroll-lock');
    inertElements = [];
    let node = root;
    while (node && node !== document.body) {
      Array.from(node.parentElement.children).forEach((sibling) => {
        if (sibling !== node && !sibling.hasAttribute('inert')) {
          sibling.setAttribute('inert', '');
          inertElements.push(sibling);
        }
      });
      node = node.parentElement;
    }
    const button = root.querySelector('[data-dcu-fullscreen]');
    if (button) {
      button.setAttribute('aria-pressed', 'true');
      setLabel(button, Drupal.t('Exit Full Screen'));
    }
    const close = root.querySelector('[data-dcu-fullscreen-close]');
    if (close) {
      close.removeAttribute('hidden');
    }
    Drupal.announce(Drupal.t('Full screen on. Press Escape to exit.'));
  }

  /**
   * Leave full screen.
   */
  function exitFullscreen(root) {
    root.classList.remove('dcu-table--fullscreen');
    document.body.classList.remove('dcu-table-scroll-lock');
    const close = root.querySelector('[data-dcu-fullscreen-close]');
    if (close) {
      close.setAttribute('hidden', '');
    }
    inertElements.forEach((el) => el.removeAttribute('inert'));
    inertElements = [];
    const button = root.querySelector('[data-dcu-fullscreen]');
    if (button) {
      button.setAttribute('aria-pressed', 'false');
      setLabel(button, Drupal.t('Full Screen'));
      button.focus();
    }
    Drupal.announce(Drupal.t('Full screen off.'));
  }

  Drupal.behaviors.dcuDataTable = {
    attach(context) {
      once('dcu-data-table', ROOT, context).forEach((root) => {
        const toolbar = root.querySelector('.dcu-table__toolbar');
        const inPlace = !!(toolbar && toolbar.dataset.dcuFragment);

        applyDensity(root, storedDensity(), false);

        // JS-only controls.
        root.querySelectorAll('[data-dcu-add-filter], [data-dcu-fullscreen], [data-dcu-density], [data-dcu-copy], [data-dcu-panel-close]')
          .forEach((el) => el.removeAttribute('hidden'));
        root.querySelectorAll('[data-dcu-density] input').forEach((radio) => {
          radio.checked = radio.value === storedDensity();
          radio.addEventListener('change', () => applyDensity(root, radio.value, true));
        });

        // Operators follow the chosen column.
        root.querySelectorAll('form.dcu-table__filters').forEach((form) => {
          form.querySelectorAll('select[name$="[property]"]').forEach((select) => {
            if (select.value) {
              narrowOperators(form, select);
            }
          });
          form.addEventListener('change', (event) => {
            if (event.target.matches('select[name$="[property]"]')) {
              narrowOperators(form, event.target);
            }
          });
        });

        root.addEventListener('click', (event) => {
          const addFilter = event.target.closest('[data-dcu-add-filter]');
          if (addFilter) {
            addFilterRow(addFilter.closest('form'));
            return;
          }
          const copy = event.target.closest('[data-dcu-copy]');
          if (copy) {
            copyLink(copy);
            return;
          }
          const panelClose = event.target.closest('[data-dcu-panel-close]');
          if (panelClose) {
            const panel = panelClose.closest('details.dcu-table__panel');
            if (panel) {
              panel.removeAttribute('open');
              panel.querySelector('summary').focus();
            }
            return;
          }
          if (event.target.closest('[data-dcu-fullscreen-close]')) {
            exitFullscreen(root);
            return;
          }
          const fullscreen = event.target.closest('[data-dcu-fullscreen]');
          if (fullscreen) {
            if (root.classList.contains('dcu-table--fullscreen')) {
              exitFullscreen(root);
            }
            else {
              enterFullscreen(root);
            }
            return;
          }
          const link = event.target.closest('a[href]');
          const plainClick = event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
          if (inPlace && plainClick && link && root.contains(link) && isStateLink(link)) {
            event.preventDefault();
            // A link inside a panel (Cancel) closes it: return to its toggle.
            const inPanel = link.closest('details.dcu-table__panel');
            const hints = inPanel && inPanel.id ? [panelToggle(inPanel)] : [];
            go(link.href, (pageUrl) => { window.location.assign(pageUrl.toString()); }, hints);
          }
        });

        if (inPlace) {
          root.addEventListener('submit', (event) => {
            const form = event.target;
            if (form.matches('form') && form.method.toLowerCase() === 'get' && toolbar.contains(form)) {
              event.preventDefault();
              submitForm(form, event.submitter);
            }
          });
        }

        // Panels: one open at a time; Escape and click-outside close
        // (both on the document, so they survive the root being replaced).
        root.querySelectorAll('details.dcu-table__panel').forEach((panel) => {
          panel.addEventListener('toggle', () => {
            if (panel.open) {
              closePanels(root, panel, false);
            }
          });
        });
      });

      once('dcu-data-table-document', 'body', context).forEach(() => {
        // Escape closes the open panel, then full screen. Bound to the
        // document because a submit leaves focus on the table root and a
        // full page load leaves it on the body, neither of which is inside
        // the panel. With nothing open the event is left for others.
        document.addEventListener('keydown', (event) => {
          if (event.key !== 'Escape') {
            return;
          }
          const root = document.querySelector(ROOT);
          if (!root) {
            return;
          }
          const open = root.querySelector('details.dcu-table__panel[open]');
          if (open) {
            open.removeAttribute('open');
            open.querySelector('summary').focus();
            return;
          }
          if (root.classList.contains('dcu-table--fullscreen')) {
            exitFullscreen(root);
          }
        });
        document.addEventListener('click', (event) => {
          const root = document.querySelector(ROOT);
          if (!root) {
            return;
          }
          const inPanel = event.target.closest('details.dcu-table__panel');
          if (!inPanel) {
            closePanels(root, null, false);
          }
        });
        window.addEventListener('popstate', () => {
          const root = document.querySelector(ROOT);
          if (root && root.querySelector('[data-dcu-fragment]')) {
            const current = new URL(window.location.href);
            load(current, current, false);
          }
        });
      });
    },
  };
})(Drupal, once);
