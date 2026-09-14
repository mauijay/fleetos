/*
 * FleetOS Turo Extras exporter.
 * Paste this file into DevTools on an authenticated https://turo.com page, then run:
 *   await FleetOSTuroExtrasExporter.run(["60681836", "60681837"])
 *
 * The helper uses the browser's existing same-origin session. It never reads or
 * exports cookies, authorization headers, messages, or guest contact details.
 */
(function installFleetOSTuroExtrasExporter(root) {
  "use strict";

  const SCHEMA = "fleetos-turo-extras-v1";

  const value = (...candidates) =>
    candidates.find((candidate) => candidate !== undefined && candidate !== null);

  const nullableString = (candidate) => {
    if (candidate === undefined || candidate === null) return null;
    const result = String(candidate).trim();
    return result === "" ? null : result;
  };

  const decimal = (candidate) => {
    const raw = value(candidate?.amount, candidate?.value, candidate);
    if (raw === undefined || raw === null || raw === "") return null;
    const normalized = String(raw).replace(/[$,\s]/g, "");
    if (!/^\d+(?:\.\d+)?$/.test(normalized)) return null;
    return Number(normalized).toFixed(2);
  };

  const quantity = (candidate) => {
    if (candidate === undefined || candidate === null || candidate === "") return null;
    const normalized = String(candidate).trim();
    return /^\d+(?:\.\d{1,3})?$/.test(normalized) ? Number(normalized) : null;
  };

  const sanitizeExtra = (extra, bookingCurrency = null) => {
    const priceObject = extra?.price;
    return {
      extra_id: nullableString(value(extra?.extraId, extra?.extra_id)),
      reservation_state_extra_id: nullableString(
        value(extra?.reservationStateExtraId, extra?.reservation_state_extra_id),
      ),
      reservation_state_id: nullableString(
        value(extra?.reservationStateId, extra?.reservation_state_id),
      ),
      type: nullableString(value(extra?.type, extra?.extraType, extra?.extra_type, extra?.value)),
      label: nullableString(value(extra?.label, extra?.name)),
      description: nullableString(extra?.description),
      price: decimal(priceObject),
      quantity: quantity(extra?.quantity),
      currency: nullableString(
        value(extra?.currency, priceObject?.currency, priceObject?.currencyCode, bookingCurrency),
      ),
      pricing_type: nullableString(value(extra?.pricingType, extra?.pricing_type)),
    };
  };

  const sanitizeReservation = (reservationId, response) => {
    const booking = value(response?.booking, response?.data?.booking, response?.reservation?.booking);
    if (!booking || !Array.isArray(booking.extras)) {
      throw new Error("Reservation response did not contain a complete booking.extras array.");
    }
    const bookingCurrency = value(booking.currency, booking.currencyCode, booking.price?.currency);
    const extras = booking.extras.map((extra) => sanitizeExtra(extra, bookingCurrency));
    for (const extra of extras) {
      if (!extra.extra_id || !extra.reservation_state_extra_id || !extra.label || !extra.price || !extra.currency) {
        throw new Error("Reservation contained an Extra missing a required sanitized identity, label, price, or currency field.");
      }
    }

    return {
      reservation_id: String(reservationId),
      trip_start: nullableString(value(booking.tripStart, booking.startDate, booking.startsAt)),
      trip_end: nullableString(value(booking.tripEnd, booking.endDate, booking.endsAt)),
      status: nullableString(value(booking.status, booking.reservationStatus)),
      snapshot_complete: true,
      extras,
    };
  };

  const normalizeReservationIds = (input) => {
    const values = Array.isArray(input) ? input : String(input || "").split(/[\s,]+/);
    return [...new Set(values.map((id) => String(id).trim()).filter((id) => /^\d+$/.test(id)))];
  };

  const exportReservations = async (input, options = {}) => {
    const ids = normalizeReservationIds(input);
    if (ids.length === 0) throw new Error("Provide at least one numeric Turo reservation ID.");
    const fetchImpl = options.fetchImpl || root.fetch?.bind(root);
    if (!fetchImpl) throw new Error("Browser fetch is unavailable.");
    const reservations = [];
    const failures = [];

    for (const reservationId of ids) {
      try {
        const response = await fetchImpl(
          `/api/reservation/detail?oppTermsAware=true&reservationId=${encodeURIComponent(reservationId)}`,
          { credentials: "same-origin" },
        );
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        reservations.push(sanitizeReservation(reservationId, await response.json()));
      } catch (error) {
        failures.push({
          reservation_id: reservationId,
          error: /^HTTP \d+$/.test(error?.message || "")
            ? error.message
            : "Reservation Extras could not be exported.",
        });
      }
    }

    return {
      schema: SCHEMA,
      exported_at: (options.now ? new Date(options.now) : new Date()).toISOString(),
      reservations,
      failures,
    };
  };

  const download = (payload) => {
    const blob = new Blob([`${JSON.stringify(payload, null, 2)}\n`], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = `fleetos-turo-extras-${payload.exported_at.slice(0, 10)}.json`;
    anchor.click();
    URL.revokeObjectURL(url);
  };

  const run = async (reservationIds) => {
    const payload = await exportReservations(reservationIds);
    download(payload);
    console.info(
      `FleetOS Extras export complete: ${payload.reservations.length} succeeded, ${payload.failures.length} failed.`,
    );
    if (payload.failures.length > 0) console.table(payload.failures);
    return payload;
  };

  root.FleetOSTuroExtrasExporter = {
    schema: SCHEMA,
    sanitizeExtra,
    sanitizeReservation,
    normalizeReservationIds,
    exportReservations,
    download,
    run,
  };
})(typeof window === "undefined" ? globalThis : window);
