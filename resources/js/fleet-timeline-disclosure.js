export const initializeFleetTimelineDisclosure = (root) => {
  const toggle = root.querySelector("[data-fleet-timeline-toggle]");
  const extraRows = [
    ...root.querySelectorAll("[data-fleet-timeline-extra]"),
  ];
  const extraGroups = [
    ...root.querySelectorAll("[data-fleet-timeline-extra-group]"),
  ];

  if (!toggle || extraRows.length === 0) return null;

  let expanded = false;
  const render = () => {
    for (const row of extraRows) row.hidden = !expanded;
    for (const group of extraGroups) group.hidden = !expanded;
    toggle.hidden = false;
    toggle.setAttribute("aria-expanded", String(expanded));
    toggle.textContent = expanded
      ? toggle.dataset.expandedLabel
      : toggle.dataset.collapsedLabel;
  };

  toggle.addEventListener("click", () => {
    expanded = !expanded;
    render();
  });
  render();

  return {
    isExpanded: () => expanded,
    setExpanded(value) {
      expanded = Boolean(value);
      render();
    },
  };
};
