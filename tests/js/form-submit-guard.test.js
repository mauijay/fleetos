import assert from "node:assert/strict";
import test from "node:test";

import { createPostSubmissionGuard } from "../../resources/js/form-submit-guard.js";

const form = (method = "post") => {
  const attributes = new Map([["method", method]]);

  return {
    getAttribute: (name) => attributes.get(name) ?? null,
    setAttribute: (name, value) => attributes.set(name, value),
    removeAttribute: (name) => attributes.delete(name),
    attribute: (name) => attributes.get(name) ?? null,
  };
};

const eventFor = (target) => ({
  target,
  prevented: false,
  preventDefault() {
    this.prevented = true;
  },
});

test("allows one POST submission and blocks any overlapping POST from the page", () => {
  const guard = createPostSubmissionGuard();
  const target = form();
  const first = eventFor(target);
  const duplicate = eventFor(target);
  const overlapping = eventFor(form());

  guard.handleSubmit(first);
  guard.handleSubmit(duplicate);
  guard.handleSubmit(overlapping);

  assert.equal(first.prevented, false);
  assert.equal(duplicate.prevented, true);
  assert.equal(overlapping.prevented, true);
  assert.equal(target.attribute("aria-busy"), "true");
});

test("does not interfere with GET forms", () => {
  const guard = createPostSubmissionGuard();
  const submission = eventFor(form("get"));

  guard.handleSubmit(submission);

  assert.equal(submission.prevented, false);
  assert.equal(submission.target.attribute("aria-busy"), null);
});

test("reset permits a restored page to submit again", () => {
  const root = form("document");
  const guard = createPostSubmissionGuard(root);
  const target = form();
  guard.handleSubmit(eventFor(target));
  assert.equal(root.attribute("data-post-submitting"), "true");

  guard.reset();
  assert.equal(target.attribute("aria-busy"), null);
  assert.equal(root.attribute("data-post-submitting"), null);
  const restoredSubmission = eventFor(target);
  guard.handleSubmit(restoredSubmission);

  assert.equal(restoredSubmission.prevented, false);
  assert.equal(target.attribute("aria-busy"), "true");
  assert.equal(root.attribute("data-post-submitting"), "true");
});
