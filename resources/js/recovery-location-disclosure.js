export const recoveryLocationDisclosureState = (locationClass) => ({
  hasLocation: locationClass !== "",
  showHnlParking: locationClass === "airport_hnl",
});

export const initializeRecoveryLocationDisclosure = (form) => {
  const location = form.querySelector("[data-recovery-location]");
  const details = form.querySelector("[data-recovery-details]");
  if (!location || !details) return;

  const sync = () => {
    const state = recoveryLocationDisclosureState(location.value);
    details.hidden = !state.hasLocation;
    details.disabled = !state.hasLocation;
    location.setAttribute("aria-expanded", String(state.hasLocation));
  };

  location.addEventListener("change", sync);
  sync();
};
