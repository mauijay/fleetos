export const createPostSubmissionGuard = (root = null) => {
  let submittingForm = null;

  const handleSubmit = (event) => {
    const form = event.target;
    const method = form?.getAttribute?.("method") ?? form?.method ?? "get";

    if (String(method).toLowerCase() !== "post") return;

    if (submittingForm !== null) {
      event.preventDefault();
      return;
    }

    submittingForm = form;
    form.setAttribute?.("aria-busy", "true");
    root?.setAttribute?.("data-post-submitting", "true");
  };

  const reset = () => {
    submittingForm?.removeAttribute?.("aria-busy");
    submittingForm = null;
    root?.removeAttribute?.("data-post-submitting");
  };

  return { handleSubmit, reset };
};
