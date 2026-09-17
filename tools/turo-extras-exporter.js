/*
 * FleetOS Turo Extras exporter.
 * Paste this file into DevTools on an authenticated https://turo.com page, then run:
 *   await FleetOSTuroExtrasExporter.run(["70000001", "70000002"])
 *
 * The helper uses the browser's existing same-origin session. It never reads or
 * exports cookies, authorization headers, messages, or guest contact details.
 */
(function installFleetOSTuroExtrasExporter(root) {
  "use strict";

  const SCHEMA = "fleetos-turo-extras-v1";

  class SanitizedExportError extends Error {
    constructor(code, message) {
      super(message);
      this.name = "SanitizedExportError";
      this.code = code;
    }
  }

  const value = (...candidates) =>
    candidates.find(
      (candidate) => candidate !== undefined && candidate !== null,
    );

  const text = (candidate, field, required = false) => {
    if (candidate === undefined || candidate === null) {
      if (required) {
        throw new SanitizedExportError(
          "malformed_extra",
          `${field} is required.`,
        );
      }
      return null;
    }
    if (typeof candidate !== "string") {
      throw new SanitizedExportError(
        "unsupported_field_type",
        `${field} used an unsupported field type.`,
      );
    }
    const result = candidate.trim();
    if (result === "" && required) {
      throw new SanitizedExportError(
        "malformed_extra",
        `${field} is required.`,
      );
    }
    return result === "" ? null : result;
  };

  const normalizeIdentifier = (candidate, field, required = false) => {
    if (candidate === undefined || candidate === null || candidate === "") {
      if (required) {
        throw new SanitizedExportError(
          "invalid_identifier",
          `${field} is required.`,
        );
      }
      return null;
    }
    if (typeof candidate === "number") {
      if (!Number.isSafeInteger(candidate) || candidate < 0) {
        throw new SanitizedExportError(
          "invalid_identifier",
          `${field} must be a safe non-negative integer identifier.`,
        );
      }
      return String(candidate);
    }
    if (typeof candidate === "bigint") {
      if (candidate < 0n) {
        throw new SanitizedExportError(
          "invalid_identifier",
          `${field} must be a safe non-negative integer identifier.`,
        );
      }
      return candidate.toString();
    }
    if (typeof candidate === "string" && /^\d+$/.test(candidate.trim())) {
      return candidate.trim();
    }
    throw new SanitizedExportError(
      "invalid_identifier",
      `${field} must be an integer identifier or digit string.`,
    );
  };

  const decimal = (candidate, field) => {
    const raw = value(candidate?.amount, candidate?.value, candidate);
    if (raw === undefined || raw === null || raw === "") {
      throw new SanitizedExportError(
        "malformed_extra",
        `${field} is required.`,
      );
    }
    if (typeof raw !== "string" && typeof raw !== "number") {
      throw new SanitizedExportError(
        "unsupported_field_type",
        `${field} used an unsupported field type.`,
      );
    }
    const normalized = String(raw).replace(/[$,\s]/g, "");
    if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
      throw new SanitizedExportError(
        "malformed_extra",
        `${field} must be a non-negative amount with at most two decimal places.`,
      );
    }
    const [whole, fraction = ""] = normalized.split(".");
    return `${whole}.${fraction.padEnd(2, "0")}`;
  };

  const quantity = (candidate) => {
    if (candidate === undefined || candidate === null || candidate === "")
      return null;
    if (typeof candidate !== "string" && typeof candidate !== "number") {
      throw new SanitizedExportError(
        "unsupported_field_type",
        "Extra quantity used an unsupported field type.",
      );
    }
    const normalized = String(candidate).trim();
    if (!/^\d+(?:\.\d{1,3})?$/.test(normalized) || Number(normalized) <= 0) {
      throw new SanitizedExportError(
        "malformed_extra",
        "Extra quantity must be greater than zero with at most three decimal places.",
      );
    }
    return Number(normalized);
  };

  const sanitizeExtra = (extra, bookingCurrency = null) => {
    if (!extra || typeof extra !== "object" || Array.isArray(extra)) {
      throw new SanitizedExportError(
        "malformed_extra",
        "Reservation contained a malformed Extra item.",
      );
    }
    const sourceType = extra.extraType;
    const hasRealType = sourceType !== undefined && sourceType !== null
      && typeof sourceType === "object" && !Array.isArray(sourceType);
    const hasSelectedPrice = Object.prototype.hasOwnProperty.call(extra, "priceWithCurrency");
    const priceObject = hasSelectedPrice ? extra.priceWithCurrency : extra.price;
    const selectedAmount = hasSelectedPrice ? priceObject?.amount : priceObject;
    return {
      extra_id: normalizeIdentifier(
        value(extra?.extraId, extra?.extra_id),
        "Extra extraId",
        true,
      ),
      reservation_state_extra_id: normalizeIdentifier(
        value(
          extra?.reservationStateExtraId,
          extra?.reservation_state_extra_id,
        ),
        "Extra reservationStateExtraId",
        true,
      ),
      reservation_state_id: normalizeIdentifier(
        value(extra?.reservationStateId, extra?.reservation_state_id),
        "Extra reservationStateId",
      ),
      type: text(
        hasRealType ? sourceType.value : value(sourceType, extra.type, extra.extra_type),
        "Extra type",
      ),
      label: text(
        hasRealType ? sourceType.label : value(extra.extraValue, extra.label, extra.name),
        "Extra label",
        true,
      ),
      description: text(extra?.description, "Extra description"),
      price: decimal(selectedAmount, "Extra price"),
      quantity: quantity(extra?.quantity),
      currency: text(
        hasSelectedPrice
          ? priceObject?.currencyCode
          : value(extra.currency, extra.currencyCode, priceObject?.currency,
            priceObject?.currencyCode, bookingCurrency),
        "Extra currency",
        true,
      ),
      pricing_type: text(
        value(extra?.extraPricingType, extra?.pricingType, extra?.pricing_type),
        "Extra pricing type",
      ),
    };
  };

  const authoritativeContainer = (response) => {
    const booking = value(
      response?.booking,
      response?.data?.booking,
      response?.reservation?.booking,
    );
    const cancelledRequest = value(
      response?.cancelledRequest,
      response?.data?.cancelledRequest,
      response?.reservation?.cancelledRequest,
    );
    const name = booking
      ? "booking"
      : cancelledRequest
        ? "cancelledRequest"
        : null;
    const container = booking || cancelledRequest;
    if (
      !container ||
      typeof container !== "object" ||
      Array.isArray(container)
    ) {
      throw new SanitizedExportError(
        "unsupported_response_shape",
        "Unsupported reservation response shape.",
      );
    }
    if (!Object.prototype.hasOwnProperty.call(container, "extras")) {
      throw new SanitizedExportError(
        "extras_array_missing",
        `Authoritative ${name}.extras array was missing.`,
      );
    }
    if (!Array.isArray(container.extras)) {
      throw new SanitizedExportError(
        "unsupported_field_type",
        `Authoritative ${name}.extras must be an array.`,
      );
    }
    return { container, name };
  };

  const sanitizeReservation = (reservationId, response) => {
    const normalizedReservationId = normalizeIdentifier(
      reservationId,
      "Reservation ID",
      true,
    );
    const { container } = authoritativeContainer(response);
    const bookingCurrency = value(
      container.currency,
      container.currencyCode,
      container.price?.currency,
      container.price?.currencyCode,
    );
    const extras = container.extras.map((extra) =>
      sanitizeExtra(extra, bookingCurrency),
    );

    return {
      reservation_id: normalizedReservationId,
      trip_start: text(
        value(container.tripStart, container.startDate, container.startsAt),
        "Reservation trip start",
      ),
      trip_end: text(
        value(container.tripEnd, container.endDate, container.endsAt),
        "Reservation trip end",
      ),
      status: text(
        value(container.status, container.reservationStatus),
        "Reservation status",
      ),
      snapshot_complete: true,
      extras,
    };
  };

  const normalizeReservationIds = (input) => {
    const values = Array.isArray(input)
      ? input
      : typeof input === "string"
        ? input.split(/[\s,]+/)
        : [input];
    const normalized = [];
    for (const id of values) {
      try {
        normalized.push(normalizeIdentifier(id, "Reservation ID", true));
      } catch (error) {
        if (!(error instanceof SanitizedExportError)) throw error;
      }
    }
    return [...new Set(normalized)];
  };

  const safeFailureMessage = (error) => {
    if (error instanceof SanitizedExportError) return error.message;
    const message = error?.message || "";
    return /^HTTP \d{3}$/.test(message)
      ? message
      : "Reservation Extras could not be exported.";
  };

  const exportReservations = async (input, options = {}) => {
    const ids = normalizeReservationIds(input);
    if (ids.length === 0)
      throw new Error("Provide at least one numeric Turo reservation ID.");
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
        reservations.push(
          sanitizeReservation(reservationId, await response.json()),
        );
      } catch (error) {
        failures.push({
          reservation_id: reservationId,
          error: safeFailureMessage(error),
        });
      }
    }

    return {
      schema: SCHEMA,
      exported_at: (options.now
        ? new Date(options.now)
        : new Date()
      ).toISOString(),
      reservations,
      failures,
    };
  };

  const download = (payload) => {
    const blob = new Blob([`${JSON.stringify(payload, null, 2)}\n`], {
      type: "application/json",
    });
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
    normalizeIdentifier,
    sanitizeExtra,
    sanitizeReservation,
    safeFailureMessage,
    normalizeReservationIds,
    exportReservations,
    download,
    run,
  };
})(typeof window === "undefined" ? globalThis : window);
