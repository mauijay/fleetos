# File Storage and Receipt Evidence

FleetOS uses the shared `files` table as the authoritative metadata record for document uploads. Airport receipt evidence is stored on the local disk under `writable/uploads/airport-receipts` and is referenced by `files.id` from reimbursement receipts and airport operations expenses.

Supported airport receipt formats are JPEG, PNG, WebP, and PDF. Server-side MIME inspection is used; file extensions alone are not trusted. The current configured upload limit is 10 MB.

Stored files use generated paths, not user-supplied filenames. Original filenames are preserved only as metadata. SHA-256 checksums are stored for duplicate detection.

Receipt files are not exposed as public URLs. Preview/download uses authenticated FleetOS routes such as `/files/receipts/{file_id}`. Internal storage paths are not shown to the operator. The same preview route is reused when a receipt is classified as trip reimbursement evidence, airport operations expense evidence, unresolved, non-business, or duplicate.

Evidence should not be physically deleted during normal operations. Filed, reimbursed, denied, or audited claim evidence should be archived rather than destroyed in a future retention workflow.
