export const commitmentFormState = (category) => ({
  showEnergy: category === "energy_override",
  showTiming: category === "timing_arrangement",
});

export const energyFieldState = (comparison) => ({
  showSingle: comparison !== "preferred_range",
  showRange: comparison === "preferred_range",
});

const setFieldsetState = (fieldset, active) => {
  if (!fieldset) return;
  fieldset.hidden = !active;
  fieldset.disabled = !active;
  fieldset.querySelectorAll("input, select, textarea").forEach((control) => {
    control.disabled = !active;
  });
};

export const initializeGuestCommitmentForm = (form) => {
  const category = form.querySelector("[data-commitment-category]");
  const handling = form.querySelector("[data-commitment-handling]");
  const energyFields = form.querySelector("[data-energy-fields]");
  const energyComparison = form.querySelector("[data-energy-comparison]");
  const singleEnergy = form.querySelector("[data-single-energy]");
  const rangeEnergy = form.querySelector("[data-range-energy]");
  const timingFields = form.querySelector("[data-timing-fields]");
  if (!category || !handling) return;

  const sync = () => {
    const state = commitmentFormState(category.value);
    setFieldsetState(energyFields, state.showEnergy);
    setFieldsetState(timingFields, state.showTiming);
    if (state.showEnergy) handling.value = "automatic_override";
    if (!state.showEnergy && handling.value === "automatic_override") {
      handling.value = "informational";
    }
    for (const option of handling.options) {
      option.hidden = option.value === "automatic_override" && !state.showEnergy;
      option.disabled = option.hidden;
    }
    const energyState = energyFieldState(energyComparison?.value);
    setFieldsetState(singleEnergy, state.showEnergy && energyState.showSingle);
    setFieldsetState(rangeEnergy, state.showEnergy && energyState.showRange);
  };

  category.addEventListener("change", sync);
  energyComparison?.addEventListener("change", sync);
  sync();
};
