import assert from "node:assert/strict";
import test from "node:test";

await import("../../tools/turo-extras-exporter.js");

const exporter = globalThis.FleetOSTuroExtrasExporter;

const successfulResponse = (payload) => ({
  ok: true,
  json: async () => payload,
});

const realExtra = (overrides = {}) => ({
  description: "Sanitized beach equipment",
  extraId: 3154920,
  extraPricingType: "PER_TRIP",
  extraType: {
    label: "Beach gear",
    value: "BEACH_GEAR",
    category: { private: "must-not-export" },
    priceRecommendationSummary: null,
  },
  priceWithCurrency: { amount: 47, currencyCode: "USD" },
  reservationStateExtraId: 8020576,
  reservationStateId: 9100001,
  quantity: 2,
  ...overrides,
});

test("exports an explicitly present empty booking.extras array as complete", () => {
  const reservation = exporter.sanitizeReservation("70000001", {
    booking: { extras: [], status: "BOOKED" },
  });

  assert.equal(reservation.reservation_id, "70000001");
  assert.equal(reservation.snapshot_complete, true);
  assert.deepEqual(reservation.extras, []);
});

test("maps the proven real Extra fields and safely normalizes numeric identifiers", async () => {
  const payload = await exporter.exportReservations(["70000002"], {
    now: "2026-09-14T08:00:00.000Z",
    fetchImpl: async () => successfulResponse({
      booking: {
        extras: [realExtra({
          guestName: "Private Guest",
          authorizationToken: "must-not-export",
        })],
        guest: { email: "private@example.test", phone: "555-0100" },
      },
      cookie: "must-not-export",
    }),
  });

  const extra = payload.reservations[0].extras[0];
  assert.deepEqual(extra, {
    extra_id: "3154920",
    reservation_state_extra_id: "8020576",
    reservation_state_id: "9100001",
    type: "BEACH_GEAR",
    label: "Beach gear",
    description: "Sanitized beach equipment",
    price: "47.00",
    quantity: 2,
    currency: "USD",
    pricing_type: "PER_TRIP",
  });
  assert.equal(JSON.stringify(payload).includes("Private Guest"), false);
  assert.equal(JSON.stringify(payload).includes("private@example.test"), false);
  assert.equal(JSON.stringify(payload).includes("must-not-export"), false);
});

test("prefers the selected real fields over stale aliases and ignores recommendations", () => {
  const extra = exporter.sanitizeExtra(realExtra({
    extraType: {
      label: "Prepaid EV recharge",
      value: "PREPAID_EV_RECHARGE",
      priceRecommendationSummary: { amount: 999, currencyCode: "USD" },
    },
    extraValue: "Stale label",
    type: "STALE_TYPE",
    price: 999,
    currency: "EUR",
    priceWithCurrency: { amount: 25.2, currencyCode: "USD" },
  }));

  assert.equal(extra.label, "Prepaid EV recharge");
  assert.equal(extra.type, "PREPAID_EV_RECHARGE");
  assert.equal(extra.price, "25.20");
  assert.equal(extra.currency, "USD");
  assert.equal(JSON.stringify(extra).includes("999"), false);
  assert.equal(JSON.stringify(extra).includes("Stale label"), false);
});

test("missing selected price never falls back to recommendation or stale price", async () => {
  for (const priceWithCurrency of [undefined, { currencyCode: "USD" }]) {
    const source = realExtra({
      priceWithCurrency,
      price: 47,
      extraType: { label: "Beach gear", value: "BEACH_GEAR", priceRecommendationSummary: { amount: 47 } },
    });
    if (priceWithCurrency === undefined) delete source.price;
    const payload = await exporter.exportReservations(["70000010"], {
      fetchImpl: async () => successfulResponse({ booking: { extras: [source] } }),
    });

    assert.equal(payload.reservations.length, 0);
    assert.deepEqual(payload.failures, [{
      reservation_id: "70000010",
      error: "Extra price is required.",
    }]);
  }
});

test("malformed selected amount and real type reject safely", async () => {
  for (const [source, expected] of [
    [realExtra({ priceWithCurrency: { amount: { private: "guest@example.test" }, currencyCode: "USD" } }),
      "Extra price used an unsupported field type."],
    [realExtra({ priceWithCurrency: { amount: 47.123, currencyCode: "USD" } }),
      "Extra price must be a non-negative amount with at most two decimal places."],
    [realExtra({ extraType: { label: { private: "guest@example.test" }, value: "BEACH_GEAR" } }),
      "Extra label used an unsupported field type."],
  ]) {
    const payload = await exporter.exportReservations(["70000011"], {
      fetchImpl: async () => successfulResponse({ booking: { extras: [source] } }),
    });

    assert.equal(payload.reservations.length, 0);
    assert.deepEqual(payload.failures, [{ reservation_id: "70000011", error: expected }]);
    assert.equal(JSON.stringify(payload).includes("guest@example.test"), false);
  }
});

test("exports all three selected Extras from a normal booking", () => {
  const reservation = exporter.sanitizeReservation("70000003", {
    booking: {
      extras: [
        realExtra(),
        realExtra({ extraId: 3199029, reservationStateExtraId: 8020577,
          extraType: { label: "Child safety seat", value: "CHILD_SAFETY_SEAT" },
          priceWithCurrency: { amount: 45, currencyCode: "USD" } }),
        realExtra({ extraId: 4000000, reservationStateExtraId: 8020578,
          extraType: { label: "Booster seat", value: "BOOSTER_SEAT" },
          priceWithCurrency: { amount: 15, currencyCode: "USD" } }),
      ],
    },
  });

  assert.equal(reservation.extras.length, 3);
  assert.deepEqual(reservation.extras.map((extra) => extra.extra_id), ["3154920", "3199029", "4000000"]);
  assert.deepEqual(reservation.extras.map((extra) => extra.price), ["47.00", "45.00", "15.00"]);
  assert.deepEqual(reservation.extras.map((extra) => extra.type), ["BEACH_GEAR", "CHILD_SAFETY_SEAT", "BOOSTER_SEAT"]);
});

test("omitted quantity remains null and numeric identities retain exact safe integers", () => {
  const extra = exporter.sanitizeExtra(realExtra({
    extraId: Number.MAX_SAFE_INTEGER,
    reservationStateExtraId: 9007199254740990,
    reservationStateId: 9007199254740989,
    quantity: undefined,
  }), "USD");

  assert.equal(extra.extra_id, "9007199254740991");
  assert.equal(extra.reservation_state_extra_id, "9007199254740990");
  assert.equal(extra.reservation_state_id, "9007199254740989");
  assert.equal(extra.quantity, null);
  assert.deepEqual(exporter.normalizeReservationIds(Number.MAX_SAFE_INTEGER + 1), []);
});

test("rejects non-integer and unsafe numeric identifiers with sanitized reasons", async () => {
  for (const invalidId of [12.5, Number.MAX_SAFE_INTEGER + 1]) {
    const payload = await exporter.exportReservations(["70000004"], {
      fetchImpl: async () => successfulResponse({
        booking: { currency: "USD", extras: [realExtra({ extraId: invalidId })] },
      }),
    });

    assert.equal(payload.reservations.length, 0);
    assert.deepEqual(payload.failures, [{
      reservation_id: "70000004",
      error: "Extra extraId must be a safe non-negative integer identifier.",
    }]);
  }
});

test("exports an explicit empty cancelledRequest.extras snapshot", () => {
  const reservation = exporter.sanitizeReservation("70000005", {
    cancelledRequest: {
      extras: [],
      reservationStatus: "CANCELLED",
    },
  });

  assert.equal(reservation.snapshot_complete, true);
  assert.equal(reservation.status, "CANCELLED");
  assert.deepEqual(reservation.extras, []);
});

test("does not treat a missing cancelledRequest.extras field as complete", async () => {
  const payload = await exporter.exportReservations(["70000006"], {
    fetchImpl: async () => successfulResponse({ cancelledRequest: { status: "CANCELLED" } }),
  });

  assert.equal(payload.reservations.length, 0);
  assert.deepEqual(payload.failures, [{
    reservation_id: "70000006",
    error: "Authoritative cancelledRequest.extras array was missing.",
  }]);
});

test("does not recursively accept an unknown Extras container", async () => {
  const payload = await exporter.exportReservations(["70000007"], {
    fetchImpl: async () => successfulResponse({ nested: { extras: [] } }),
  });

  assert.deepEqual(payload.failures, [{
    reservation_id: "70000007",
    error: "Unsupported reservation response shape.",
  }]);
});

test("rejects unsupported Extra field types without leaking their values", async () => {
  const payload = await exporter.exportReservations(["70000008"], {
    fetchImpl: async () => successfulResponse({
      booking: {
        currency: "USD",
        extras: [realExtra({ description: { private: "guest@example.test" } })],
      },
    }),
  });

  assert.deepEqual(payload.failures, [{
    reservation_id: "70000008",
    error: "Extra description used an unsupported field type.",
  }]);
  assert.equal(JSON.stringify(payload).includes("guest@example.test"), false);
});

test("preserves the established sanitized aliases and server-contract keys", () => {
  const extra = exporter.sanitizeExtra({
    extraId: "4",
    reservationStateExtraId: "5",
    reservationStateId: null,
    label: "Portable GPS",
    description: "Sanitized alias",
    type: "PORTABLE_GPS",
    price: { amount: "79", currency: "USD" },
    quantity: null,
    pricingType: "PER_TRIP",
  });

  assert.deepEqual(Object.keys(extra), [
    "extra_id", "reservation_state_extra_id", "reservation_state_id", "type", "label",
    "description", "price", "quantity", "currency", "pricing_type",
  ]);
  assert.equal(extra.price, "79.00");
  assert.equal(extra.quantity, null);
});

test("fetches unique reservations sequentially and retains sanitized HTTP failures", async () => {
  let active = 0;
  let peak = 0;
  const calls = [];
  const payload = await exporter.exportReservations("7, 8 7", {
    now: "2026-09-14T08:00:00.000Z",
    fetchImpl: async (url, options) => {
      calls.push([url, options]);
      active += 1;
      peak = Math.max(peak, active);
      await Promise.resolve();
      active -= 1;
      if (url.endsWith("8")) return { ok: false, status: 404 };
      return successfulResponse({ booking: { extras: [] } });
    },
  });

  assert.equal(peak, 1);
  assert.equal(payload.reservations.length, 1);
  assert.deepEqual(payload.failures, [{ reservation_id: "8", error: "HTTP 404" }]);
  assert.deepEqual(calls[0][1], { credentials: "same-origin" });
});

test("unexpected runtime failures are replaced with a generic safe message", async () => {
  const payload = await exporter.exportReservations(["70000009"], {
    fetchImpl: async () => successfulResponse({
      get booking() {
        throw new Error("private@example.test bearer-secret");
      },
    }),
  });

  assert.deepEqual(payload.failures, [{
    reservation_id: "70000009",
    error: "Reservation Extras could not be exported.",
  }]);
  assert.equal(JSON.stringify(payload).includes("private@example.test"), false);
  assert.equal(JSON.stringify(payload).includes("bearer-secret"), false);
});
