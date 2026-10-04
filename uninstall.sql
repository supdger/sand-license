-- Destructive lifecycle: run only with explicit uninstall authorization and backup.
-- Permission code equals slug. Use an escaped code prefix, not the app-name slug guard.
DELETE FROM sand_system_role_menu WHERE menu_id IN (
 SELECT id FROM sand_system_menu
 WHERE code = 'SandLicense' OR code = 'SandLicenseCenter' OR code LIKE 'sand\_license:%' ESCAPE '\'
);
DELETE FROM sand_system_menu
WHERE code = 'SandLicense' OR code = 'SandLicenseCenter' OR code LIKE 'sand\_license:%' ESCAPE '\';
ALTER TABLE sand_license_claim DROP CONSTRAINT sand_license_claim_code_fk;
DROP TABLE sand_license_event;
DROP TABLE sand_license_request_dedup;
DROP TABLE sand_license_enrollment_ticket;
DROP TABLE sand_license_challenge;
DROP TABLE sand_license_lease;
DROP TABLE sand_license_activation;
DROP TABLE sand_license_redemption_code;
DROP TABLE sand_license_claim;
DROP TABLE sand_license_grant;
DROP TABLE sand_license_entitlement;
DROP TABLE sand_license_fulfillment_event;
DROP TABLE sand_license_fulfillment;
DROP TABLE sand_license_sku_mapping;
DROP TABLE sand_license_plan;
DROP TABLE sand_license_product;
