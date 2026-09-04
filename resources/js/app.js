import "../css/app.css";
import { resolveHnlParkingState } from "./hnl-parking-state.js";

const initializeHnlParking = (group) => {
  const garage = group.querySelector("[data-hnl-garage]");
  const level = group.querySelector("[data-hnl-level]");
  const row = group.querySelector("[data-hnl-row]");
  const location = group.dataset.locationSelect
    ? document.getElementById(group.dataset.locationSelect)
    : null;
  const locationDetail = group
    .closest("form")
    ?.querySelector("[data-location-detail]");
  const locationDetailInput = locationDetail?.querySelector("input");

  if (!garage || !level || !row) return;
  const rowGarages = Object.fromEntries(
    [...row.options]
      .filter((option) => option.value)
      .map((option) => [option.value, option.dataset.garage]),
  );
  const garageLevels = Object.fromEntries(
    [...garage.options]
      .filter((option) => option.value)
      .map((option) => [option.value, Number(option.dataset.maxLevel)]),
  );

  const sync = (source) => {
    const state = resolveHnlParkingState({
      source,
      garageCode: garage.value,
      row: row.value,
      level: level.value,
      rowGarages,
      garageLevels,
    });
    garage.value = state.garageCode;
    row.value = state.row;
    level.value = state.level;
    for (const option of garage.options) {
      option.disabled =
        option.value !== "" &&
        state.selectedRowGarage !== "" &&
        option.value !== state.selectedRowGarage;
    }
    for (const option of level.options) {
      option.disabled =
        option.value !== "" && !state.allowedLevels.includes(option.value);
    }
    for (const option of row.options) {
      option.disabled =
        option.value !== "" && !state.allowedRows.includes(option.value);
    }
  };

  const toggle = () => {
    const active = !location || location.value === "airport_hnl";
    group.hidden = !active;
    if (locationDetail) locationDetail.hidden = active;
    if (locationDetailInput) locationDetailInput.disabled = active;
    for (const field of [garage, level, row]) {
      field.disabled = !active;
      field.required = active;
    }
    if (active) sync();
  };

  garage.addEventListener("change", () => sync("garage"));
  row.addEventListener("change", () => sync("row"));
  location?.addEventListener("change", toggle);
  toggle();
};

document.querySelectorAll("[data-hnl-parking]").forEach(initializeHnlParking);
