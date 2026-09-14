export const initializeCopyField = (button, documentRef = document) => {
  const target = documentRef.getElementById(button.dataset.copyTarget || "");
  const status = documentRef.querySelector("[data-copy-status]");
  if (!target) return;

  button.addEventListener("click", async () => {
    try {
      const clipboard = documentRef.defaultView?.navigator?.clipboard ?? globalThis.navigator?.clipboard;
      await clipboard.writeText(target.value);
      if (status) status.textContent = "Reservation IDs copied.";
    } catch {
      target.focus();
      target.select();
      if (status) status.textContent = "Copy was blocked; the IDs are selected for manual copy.";
    }
  });
};
