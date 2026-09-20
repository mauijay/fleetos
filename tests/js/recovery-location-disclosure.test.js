import assert from "node:assert/strict";
import test from "node:test";

import { recoveryLocationDisclosureState } from "../../resources/js/recovery-location-disclosure.js";

test("blank recovery location keeps the detailed form safely hidden", () => {
  assert.deepEqual(recoveryLocationDisclosureState(""), {
    hasLocation: false,
    showHnlParking: false,
  });
});

test("ordinary recovery locations reveal details without HNL parking", () => {
  for (const locationClass of ["home", "waikiki_hotel", "other_delivery"]) {
    assert.deepEqual(recoveryLocationDisclosureState(locationClass), {
      hasLocation: true,
      showHnlParking: false,
    });
  }
});

test("Airport HNL reveals the recovery details and HNL parking controls", () => {
  assert.deepEqual(recoveryLocationDisclosureState("airport_hnl"), {
    hasLocation: true,
    showHnlParking: true,
  });
});
