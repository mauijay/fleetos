export const resolveHnlParkingState = ({
  source,
  garageCode,
  row,
  level,
  rowGarages,
  garageLevels,
}) => {
  const rowGarage = rowGarages[row] || "";
  const resolvedGarage = source === "row" && rowGarage ? rowGarage : garageCode;
  const resolvedRow =
    resolvedGarage && rowGarage && rowGarage !== resolvedGarage ? "" : row;
  const selectedRowGarage = rowGarages[resolvedRow] || "";
  const maxLevel =
    garageLevels[resolvedGarage] || Math.max(...Object.values(garageLevels));
  const resolvedLevel = Number(level) > maxLevel ? "" : level;

  return {
    garageCode: resolvedGarage,
    row: resolvedRow,
    level: resolvedLevel,
    maxLevel,
    selectedRowGarage,
    allowedRows: Object.keys(rowGarages).filter(
      (candidate) =>
        !resolvedGarage || rowGarages[candidate] === resolvedGarage,
    ),
    allowedLevels: Array.from({ length: maxLevel }, (_, index) =>
      String(index + 1),
    ),
  };
};
