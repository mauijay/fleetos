import assert from "node:assert/strict";
import test from "node:test";

await import("../../tools/turo-extras-exporter.js");

const exporter = globalThis.FleetOSTuroExtrasExporter;

test("exports a deterministic sanitized whitelist and preserves omitted quantity", async () => {
  const calls = [];
  const payload = await exporter.exportReservations(["60681836"], {
    now: "2026-09-13T20:00:00.000Z",
    fetchImpl: async (url, options) => {
      calls.push([url, options]);
      return {
        ok: true,
        json: async () => ({
          booking: {
            status: "BOOKED",
            tripStart: "2026-09-14T10:00:00-10:00",
            guest: { email: "private@example.test", phone: "555-0100" },
            extras: [{
              extraId: 3154920,
              reservationStateExtraId: 8020576,
              label: "Beach gear",
              description: "Beach kit",
              type: "BEACH_GEAR",
              price: { amount: "47", currency: "USD" },
              authorizationToken: "must-not-export",
            }],
          },
        }),
      };
    },
  });

  assert.equal(payload.schema, "fleetos-turo-extras-v1");
  assert.equal(payload.exported_at, "2026-09-13T20:00:00.000Z");
  assert.equal(payload.reservations[0].extras[0].quantity, null);
  assert.equal(payload.reservations[0].extras[0].price, "47.00");
  assert.deepEqual(Object.keys(payload.reservations[0].extras[0]), [
    "extra_id", "reservation_state_extra_id", "reservation_state_id", "type", "label",
    "description", "price", "quantity", "currency", "pricing_type",
  ]);
  assert.equal(JSON.stringify(payload).includes("private@example.test"), false);
  assert.equal(JSON.stringify(payload).includes("must-not-export"), false);
  assert.deepEqual(calls[0][1], { credentials: "same-origin" });
});

test("fetches multiple unique IDs sequentially and records safe failures", async () => {
  let active = 0;
  let peak = 0;
  const payload = await exporter.exportReservations("7, 8 7", {
    now: "2026-09-13T20:00:00.000Z",
    fetchImpl: async (url) => {
      active += 1;
      peak = Math.max(peak, active);
      await Promise.resolve();
      active -= 1;
      if (url.endsWith("8")) return { ok: false, status: 404 };
      return {
        ok: true,
        json: async () => ({ booking: { extras: [{ extraId: "4", reservationStateExtraId: "5", label: "Portable GPS", price: "79", currency: "USD" }] } }),
      };
    },
  });

  assert.equal(peak, 1);
  assert.equal(payload.reservations.length, 1);
  assert.deepEqual(payload.failures, [{ reservation_id: "8", error: "HTTP 404" }]);
});
