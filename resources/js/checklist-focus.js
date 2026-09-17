export const focusChecklistAnchor = (documentRef, hash) => {
  const id = hash?.replace(/^#/, "");
  if (!id || !/^(checklist-action-[a-z0-9_-]+|readiness-heading|handoff-entry|position-entry|exceptional-disposition)$/.test(id)) {
    return false;
  }
  const target = documentRef.getElementById(id);
  if (!target) return false;
  if (!target.hasAttribute("tabindex")) target.setAttribute("tabindex", "-1");
  target.focus({ preventScroll: false });
  return true;
};
