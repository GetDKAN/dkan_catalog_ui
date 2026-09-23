/**
 * @file
 * Upgrades dataset tabs to an ARIA tablist and syncs the URL hash.
 */
(function (Drupal, once) {
  'use strict';

  function activate(container, links, panels, index, updateHash) {
    links.forEach(function (link, i) {
      var selected = i === index;
      link.setAttribute('aria-selected', selected ? 'true' : 'false');
      link.setAttribute('tabindex', selected ? '0' : '-1');
      panels[i].hidden = !selected;
    });
    if (updateHash && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', '#' + panels[index].id);
    }
  }

  function indexFromHash(panels) {
    var hash = window.location.hash.replace(/^#/, '');
    if (!hash) {
      return 0;
    }
    for (var i = 0; i < panels.length; i++) {
      if (panels[i].id === hash) {
        return i;
      }
    }
    return 0;
  }

  Drupal.behaviors.dcuDatasetTabs = {
    attach: function (context) {
      once('dcu-dataset-tabs', '[data-dcu-tabs]', context).forEach(function (container) {
        var list = container.querySelector('.dcu-tabs__list');
        var links = Array.prototype.slice.call(container.querySelectorAll('.dcu-tabs__link'));
        var panels = Array.prototype.slice.call(container.querySelectorAll('.dcu-tabs__panel'));
        if (!list || links.length === 0 || links.length !== panels.length) {
          return;
        }
        container.classList.add('dcu-tabs--enhanced');
        list.setAttribute('role', 'tablist');
        links.forEach(function (link, i) {
          link.parentNode.setAttribute('role', 'presentation');
          link.setAttribute('role', 'tab');
          link.setAttribute('aria-controls', panels[i].id);
          panels[i].setAttribute('role', 'tabpanel');
          panels[i].setAttribute('tabindex', '0');
          link.addEventListener('click', function (event) {
            event.preventDefault();
            activate(container, links, panels, i, true);
          });
          link.addEventListener('keydown', function (event) {
            var next = null;
            if (event.key === 'ArrowRight') {
              next = (i + 1) % links.length;
            }
            else if (event.key === 'ArrowLeft') {
              next = (i - 1 + links.length) % links.length;
            }
            else if (event.key === 'Home') {
              next = 0;
            }
            else if (event.key === 'End') {
              next = links.length - 1;
            }
            if (next !== null) {
              event.preventDefault();
              activate(container, links, panels, next, true);
              links[next].focus();
            }
          });
        });
        activate(container, links, panels, indexFromHash(panels), false);
        window.addEventListener('hashchange', function () {
          activate(container, links, panels, indexFromHash(panels), false);
        });
      });
    }
  };
})(Drupal, once);
