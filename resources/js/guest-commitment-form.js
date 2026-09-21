export const commitmentFormState = (category) => ({
  showEnergy: category === "energy_override",
  showTiming: category === "timing_arrangement",
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
  };

  category.addEventListener("change", sync);
  sync();
};
