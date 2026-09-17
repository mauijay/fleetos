import assert from "node:assert/strict";
import test from "node:test";

import { focusChecklistAnchor } from "../../resources/js/checklist-focus.js";

test("focuses the checklist PRG target without changing native fragment navigation", () => {
  let focused = false;
  const attributes = new Set();
  const row = {
    hasAttribute: (name) => attributes.has(name),
    setAttribute: (name, value) => attributes.add(name + value),
    focus: ({ preventScroll }) => { focused = preventScroll === false; },
  };
  const documentRef = { getElementById: (id) => id === "checklist-action-energy_known" ? row : null };

  assert.equal(focusChecklistAnchor(documentRef, "#checklist-action-energy_known"), true);
  assert.equal(focused, true);
  assert.equal(focusChecklistAnchor(documentRef, "#unrelated"), false);
});
