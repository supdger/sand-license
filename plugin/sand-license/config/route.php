<?php

use app\SandLicense\Controller\ClaimPageController;
use app\SandLicense\Controller\FulfillmentController;
use app\SandLicense\Controller\LicenseController;
use plugin\SandLicense\app\admin\controller\ManagementController;
use plugin\SandLicense\app\middleware\ChallengeRateLimit;
use plugin\sandadmin\app\middleware\CheckAuth;
use plugin\sandadmin\app\middleware\CheckLogin;
use Webman\Route;

Route::post('/api/sand-license/v1/challenges', [LicenseController::class, 'challenge'])->middleware([ChallengeRateLimit::class]);
Route::post('/api/sand-license/v1/redemptions', [LicenseController::class, 'redeem']);
Route::post('/api/sand-license/v1/leases/renew', [LicenseController::class, 'renew']);
Route::post('/api/sand-license/v1/entitlements/current', [LicenseController::class, 'current']);
Route::post('/api/sand-license/v1/activations/release', [LicenseController::class, 'release']);
Route::post('/api/sand-license/v1/enrollment-tickets', [LicenseController::class, 'ticket']);
Route::post('/api/sand-license/v1/activations/enroll', [LicenseController::class, 'enroll']);
Route::get('/api/sand-license/v1/.well-known/jwks.json', [LicenseController::class, 'jwks']);
Route::post('/api/sand-license/v1/fulfillments/events', [FulfillmentController::class, 'ingest']);
Route::get('/api/sand-license/v1/fulfillments/{id}', [FulfillmentController::class, 'read']);
Route::post('/api/sand-license/v1/fulfillments/claims/{id}/credential/reissue', [FulfillmentController::class, 'credentialReissue']);
Route::get('/api/sand-license/v1/memberships/current', [FulfillmentController::class, 'membership']);
Route::post('/api/sand-license/v1/claims/{id}/status', [FulfillmentController::class, 'claimStatus']);
Route::post('/api/sand-license/v1/claims/{id}/claim', [FulfillmentController::class, 'claim']);
Route::post('/api/sand-license/v1/claims/{id}/reissue', [FulfillmentController::class, 'reissue']);
Route::get('/license/claim', [ClaimPageController::class, 'index']);
Route::get('/license/claim/claim.css', [ClaimPageController::class, 'css']);
Route::get('/license/claim/claim.js', [ClaimPageController::class, 'javascript']);

Route::group('/app/sand-license/admin', function (): void {
    Route::get('/product/index', [ManagementController::class, 'productIndex']);
    Route::get('/product/read', [ManagementController::class, 'productRead']);
    Route::post('/product/save', [ManagementController::class, 'productSave']);
    Route::post('/product/publish', [ManagementController::class, 'productPublish']);
    Route::get('/plan/index', [ManagementController::class, 'planIndex']);
    Route::get('/plan/read', [ManagementController::class, 'planRead']);
    Route::post('/plan/save', [ManagementController::class, 'planSave']);
    Route::post('/plan/publish', [ManagementController::class, 'planPublish']);
    Route::get('/code/index', [ManagementController::class, 'codeIndex']);
    Route::get('/code/read', [ManagementController::class, 'codeRead']);
    Route::post('/code/issue', [ManagementController::class, 'codeIssue']);
    Route::get('/code/status', [ManagementController::class, 'codeStatus']);
    Route::post('/code/reissue', [ManagementController::class, 'codeReissue']);
    Route::post('/code/revoke', [ManagementController::class, 'codeRevoke']);
    Route::get('/entitlement/index', [ManagementController::class, 'entitlementIndex']);
    Route::get('/entitlement/read', [ManagementController::class, 'entitlementRead']);
    Route::post('/entitlement/suspend', [ManagementController::class, 'entitlementSuspend']);
    Route::post('/entitlement/revoke', [ManagementController::class, 'entitlementRevoke']);
    Route::post('/entitlement/ticket', [ManagementController::class, 'entitlementTicket']);
    Route::get('/activation/index', [ManagementController::class, 'activationIndex']);
    Route::get('/activation/read', [ManagementController::class, 'activationRead']);
    Route::post('/activation/release', [ManagementController::class, 'activationRelease']);
    Route::post('/activation/reset', [ManagementController::class, 'activationReset']);
    Route::get('/sku-mapping/index', [ManagementController::class, 'skuIndex']);
    Route::get('/sku-mapping/read', [ManagementController::class, 'skuRead']);
    Route::post('/sku-mapping/save', [ManagementController::class, 'skuSave']);
    Route::get('/fulfillment/index', [ManagementController::class, 'fulfillmentIndex']);
    Route::get('/fulfillment/read', [ManagementController::class, 'fulfillmentRead']);
    Route::get('/membership/index', [ManagementController::class, 'membershipIndex']);
    Route::get('/membership/read', [ManagementController::class, 'membershipRead']);
    Route::get('/event/index', [ManagementController::class, 'eventIndex']);
    Route::get('/event/read', [ManagementController::class, 'eventRead']);
})->middleware([CheckLogin::class, CheckAuth::class]);

// Secrets stay out of SystemLog; append-only domain events provide redacted audit.
Route::disableDefaultRoute('sand-license');
