import assert from "node:assert/strict";
import test from "node:test";

import { combineLocalDateTime } from "../../resources/js/local-datetime.js";

test("combines Honolulu-local date and exact minute without changing precision", () => {
  assert.equal(combineLocalDateTime("2026-09-08", "21:42"), "2026-09-08T21:42");
});

test("rejects incomplete date or time input", () => {
  assert.equal(combineLocalDateTime("2026-09-08", ""), "");
  assert.equal(combineLocalDateTime("", "21:42"), "");
});
