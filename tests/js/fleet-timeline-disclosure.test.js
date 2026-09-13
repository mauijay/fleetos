import assert from "node:assert/strict";
import test from "node:test";

import { initializeFleetTimelineDisclosure } from "../../resources/js/fleet-timeline-disclosure.js";

const element = (dataset = {}) => {
  const attributes = new Map();
  const listeners = new Map();

  return {
    dataset,
    hidden: false,
    textContent: "",
    addEventListener: (name, listener) => listeners.set(name, listener),
    setAttribute: (name, value) => attributes.set(name, value),
    attribute: (name) => attributes.get(name) ?? null,
    click: () => listeners.get("click")?.(),
  };
};

test("compacts excess timeline rows and expands and collapses them in place", () => {
  const toggle = element({
    collapsedLabel: "Show next 7 days",
    expandedLabel: "Show less",
  });
  toggle.hidden = true;
  const extraRows = [element(), element()];
  const extraGroups = [element()];
  const root = {
    querySelector: () => toggle,
    querySelectorAll: (selector) =>
      selector === "[data-fleet-timeline-extra]" ? extraRows : extraGroups,
  };

  const disclosure = initializeFleetTimelineDisclosure(root);

  assert.equal(disclosure.isExpanded(), false);
  assert.equal(toggle.hidden, false);
  assert.equal(toggle.attribute("aria-expanded"), "false");
  assert.equal(toggle.textContent, "Show next 7 days");
  assert.deepEqual(extraRows.map((row) => row.hidden), [true, true]);
  assert.equal(extraGroups[0].hidden, true);

  toggle.click();
  assert.equal(disclosure.isExpanded(), true);
  assert.equal(toggle.attribute("aria-expanded"), "true");
  assert.equal(toggle.textContent, "Show less");
  assert.deepEqual(extraRows.map((row) => row.hidden), [false, false]);
  assert.equal(extraGroups[0].hidden, false);

  toggle.click();
  assert.equal(disclosure.isExpanded(), false);
  assert.deepEqual(extraRows.map((row) => row.hidden), [true, true]);
  assert.equal(extraGroups[0].hidden, true);
});

test("leaves a fully visible small schedule untouched", () => {
  const toggle = element();
  toggle.hidden = true;
  const root = {
    querySelector: () => toggle,
    querySelectorAll: () => [],
  };

  assert.equal(initializeFleetTimelineDisclosure(root), null);
  assert.equal(toggle.hidden, true);
});
