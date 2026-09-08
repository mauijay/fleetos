export const combineLocalDateTime = (date, time) => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !/^\d{2}:\d{2}$/.test(time)) {
    return "";
  }

  return `${date}T${time}`;
};
