import assert from "node:assert/strict";
import test from "node:test";

import { initializeCopyField } from "../../resources/js/copy-field.js";

test("copies the active-company reservation ID field and announces success", async () => {
  let click;
  let copied;
  const target = { value: "70000001\n70000002" };
  const status = { textContent: "" };
  const button = {
    dataset: { copyTarget: "ids" },
    addEventListener: (_event, callback) => { click = callback; },
  };
  const documentRef = {
    defaultView: { navigator: { clipboard: { writeText: async (value) => { copied = value; } } } },
    getElementById: () => target,
    querySelector: () => status,
  };

  initializeCopyField(button, documentRef);
  await click();

  assert.equal(copied, target.value);
  assert.equal(status.textContent, "Reservation IDs copied.");
});
