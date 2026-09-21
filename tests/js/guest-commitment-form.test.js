import assert from "node:assert/strict";
import test from "node:test";

import { commitmentFormState } from "../../resources/js/guest-commitment-form.js";

test("energy override exposes only structured energy controls", () => {
  assert.deepEqual(commitmentFormState("energy_override"), {
    showEnergy: true,
    showTiming: false,
  });
});

test("timing arrangement exposes only arranged date and time", () => {
  assert.deepEqual(commitmentFormState("timing_arrangement"), {
    showEnergy: false,
    showTiming: true,
  });
});

test("free-text categories keep structured controls hidden", () => {
  assert.deepEqual(commitmentFormState("guest_amenity"), {
    showEnergy: false,
    showTiming: false,
  });
});
