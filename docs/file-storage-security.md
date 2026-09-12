# File Storage and Receipt Evidence

FleetOS uses the shared `files` table as the authoritative metadata record for document uploads. Airport receipt evidence is stored on the local disk under `writable/uploads/airport-receipts` and is referenced by `files.id` from reimbursement receipts and airport operations expenses.

Supported airport receipt formats are JPEG, PNG, WebP, and PDF. Server-side MIME inspection is used; file extensions alone are not trusted. The current configured upload limit is 10 MB.

Stored files use generated paths, not user-supplied filenames. Original filenames are preserved only as metadata. SHA-256 checksums are stored for duplicate detection.

Receipt files are not exposed as public URLs. Preview/download uses the authenticated, admin-authorized parent route `/operations/airport/reimbursements/receipts/{receipt_id}/file`. A raw `files.id`, checksum, or storage path never grants document access. The company-scoped receipt must own the referenced live file, and the resolved file must remain inside the private airport-receipt storage root. The same parent-authorized preview route is reused when a receipt is classified as trip reimbursement evidence, airport operations expense evidence, unresolved, non-business, or duplicate. Other file domains must likewise introduce parent-authorized domain routes rather than generic file-ID routes.

Evidence should not be physically deleted during normal operations. Filed, reimbursed, denied, or audited claim evidence should be archived rather than destroyed in a future retention workflow.
