import assert from "node:assert/strict";
import test from "node:test";

import { resolveHnlParkingState } from "../../resources/js/hnl-parking-state.js";

const rowGarages = {
  F: "international",
  G: "international",
  H: "international",
  J: "international",
  A: "terminal_1",
  B: "terminal_1",
  C: "terminal_1",
  D: "terminal_1",
  E: "terminal_1",
  K: "terminal_2",
  L: "terminal_2",
  M: "terminal_2",
  N: "terminal_2",
};
const garageLevels = { international: 8, terminal_1: 8, terminal_2: 6 };

const resolve = (overrides) =>
  resolveHnlParkingState({
    source: "row",
    garageCode: "",
    row: "",
    level: "",
    rowGarages,
    garageLevels,
    ...overrides,
  });

test("row-first selection derives each canonical garage", () => {
  assert.equal(resolve({ row: "M" }).garageCode, "terminal_2");
  assert.equal(resolve({ row: "C" }).garageCode, "terminal_1");
  assert.equal(resolve({ row: "F" }).garageCode, "international");
});

test("Row M clears Level 7 and constrains Terminal 2 levels", () => {
  const state = resolve({ row: "M", level: "7" });

  assert.equal(state.level, "");
  assert.deepEqual(state.allowedLevels, ["1", "2", "3", "4", "5", "6"]);
  assert.deepEqual(state.allowedRows, ["K", "L", "M", "N"]);
});

test("garage-first selection constrains rows and preserves valid levels", () => {
  const international = resolve({
    source: "garage",
    garageCode: "international",
    level: "7",
  });
  const terminalOne = resolve({ source: "garage", garageCode: "terminal_1" });
  const terminalTwo = resolve({
    source: "garage",
    garageCode: "terminal_2",
    level: "6",
  });

  assert.deepEqual(international.allowedRows, ["F", "G", "H", "J"]);
  assert.equal(international.level, "7");
  assert.deepEqual(terminalOne.allowedRows, ["A", "B", "C", "D", "E"]);
  assert.deepEqual(terminalTwo.allowedRows, ["K", "L", "M", "N"]);
  assert.equal(terminalTwo.level, "6");
});

test("garage-first selection clears an incompatible row", () => {
  const state = resolve({
    source: "garage",
    garageCode: "international",
    row: "M",
  });

  assert.equal(state.row, "");
  assert.equal(state.garageCode, "international");
});
