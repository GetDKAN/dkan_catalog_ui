/**
 * @param Drupal
 * @param once
 * @file
 * Upgrades dataset tabs to an ARIA tablist and syncs the URL hash.
 */
(function (Drupal, once) {
  "use strict";

  function activate(links, panels, index, updateHash) {
    links.forEach((link, i) => {
      const selected = i === index;
      link.setAttribute("aria-selected", selected ? "true" : "false");
      link.setAttribute("tabindex", selected ? "0" : "-1");
      panels[i].hidden = !selected;
    });
    if (updateHash) {
      window.history.replaceState(null, "", `#${panels[index].id}`);
    }
  }

  /**
   * The panel that is, or contains, the element the URL hash points at.
   *
   * Deep links such as #dcu-table land on the panel containing the target.
   * @param panels
   */
  function indexFromHash(panels) {
    const hash = window.location.hash.replace(/^#/, "");
    const target = hash ? document.getElementById(hash) : null;
    const index = target
      ? panels.findIndex((panel) => panel.contains(target))
      : -1;
    return index === -1 ? 0 : index;
  }

  Drupal.behaviors.dcuDatasetTabs = {
    attach(context) {
      once("dcu-dataset-tabs", "[data-dcu-tabs]", context).forEach(
        (container) => {
          const list = container.querySelector(".dcu-tabs__list");
          const links = Array.from(
            container.querySelectorAll(".dcu-tabs__link"),
          );
          const panels = Array.from(
            container.querySelectorAll(".dcu-tabs__panel"),
          );
          if (!list || links.length === 0 || links.length !== panels.length) {
            return;
          }
          container.classList.add("dcu-tabs--enhanced");
          // The tab names the visible panel; keep its heading for screen
          // readers only.
          container
            .querySelectorAll(".dcu-tabs__panel-heading")
            .forEach((heading) => heading.classList.add("visually-hidden"));
          list.setAttribute("role", "tablist");
          links.forEach((link, i) => {
            link.parentNode.setAttribute("role", "presentation");
            link.setAttribute("role", "tab");
            link.setAttribute("aria-controls", panels[i].id);
            panels[i].setAttribute("role", "tabpanel");
            panels[i].setAttribute("tabindex", "0");
            link.addEventListener("click", (event) => {
              event.preventDefault();
              activate(links, panels, i, true);
            });
            link.addEventListener("keydown", (event) => {
              const moves = {
                ArrowRight: (i + 1) % links.length,
                ArrowLeft: (i - 1 + links.length) % links.length,
                Home: 0,
                End: links.length - 1,
              };
              if (event.key in moves) {
                event.preventDefault();
                activate(links, panels, moves[event.key], true);
                links[moves[event.key]].focus();
              }
            });
          });
          activate(links, panels, indexFromHash(panels), false);
          window.addEventListener("hashchange", () => {
            activate(links, panels, indexFromHash(panels), false);
          });
        },
      );
    },
  };
})(Drupal, once);
